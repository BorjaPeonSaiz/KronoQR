<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El aislamiento del ambito `self:read`** (RF-ID-07, RL-05, regla dura 18,
 * doc 02 §9.5).
 *
 * Es la prueba mas importante de la tarea 1.11. Lo que protege no es una lista
 * de nombres: son las horas a las que otra persona entro y salio cada dia. El
 * Gherkin del doc 01 §11 lo cierra con una linea que no admite matices:
 *
 *     Y no puede acceder a datos de ningun otro empleado
 *
 * Se comprueban las **tres** barreras del §7.3 por separado, para que ninguna
 * pueda quedarse sola sosteniendo la promesa:
 *
 *   1. **La forma de la ruta.** `/me/workdays` y `/me/export` no tienen
 *      `{uuid}`, asi que no hay identificador que manipular. Se intenta de todas
 *      las formas plausibles —segmento extra, parametro de consulta, cuerpo,
 *      cabecera— y ninguna cambia lo que se devuelve.
 *   2. **El ambito del token.** `self:read` no alcanza ningun endpoint de
 *      gestion, y `attendance:read` no alcanza `/me/*`.
 *   3. **La policy.** Ningun rol de gestion entra por `/me/*`, ni siquiera
 *      `admin`.
 */

uses(RefreshDatabase::class);

/**
 * Dos empleados del mismo centro y la sesion de portal del primero.
 *
 * Que sean del mismo centro es deliberado: si el aislamiento dependiera del
 * centro y no de la persona, esta prueba no lo detectaria.
 *
 * @return array{token: string, site: int, mine: string, theirs: string}
 */
function dosEmpleadosYUnPortal(): array
{
    // Techo alto: lo que se mide aqui es la autorizacion, no el limite de tasa,
    // y varias de estas pruebas hacen mas de diez peticiones seguidas.
    config()->set('identity.portal.rate_limit_per_minute', 10_000);

    $centro = WorkforceFixtures::site('Hotel del aislamiento');

    $mio = WorkforceFixtures::employee($centro);
    $ajeno = WorkforceFixtures::employee($centro);

    return [
        'token' => PortalLogins::open($mio),
        'site' => $centro,
        'mine' => $mio,
        'theirs' => $ajeno,
    ];
}

/**
 * Una jornada cerrada registrada por el quiosco, con su proyeccion recalculada.
 * Se escribe con el agregado: lo que se prueba aqui es la lectura.
 */
function aislamientoJornada(int $site, string $employee, string $workDate, string $entrada, string $salida): void
{
    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($workDate, Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($entrada), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::inMadrid($salida), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    app(WorkDayRepository::class)->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            app(DailyTotalsProjector::class)->handle($evento);
        }
    }
}

it('rechaza como campo desconocido un identificador ajeno en la consulta de /me/workdays', function (string $parametro): void {
    // La barrera 1. Ninguna de estas formas existe en el contrato; se prueban
    // porque lo que hay que demostrar no es que el contrato no las describa,
    // sino que el servidor no las atiende. Un desenlace y uno solo (R4-QA-02):
    // `ValidatesWorkDateRange` usa `RejectsUnknownInput`, asi que el parametro
    // ajeno da `422` ANTES de leer nada, ni lo del otro ni lo propio.
    $contexto = dosEmpleadosYUnPortal();

    $respuesta = Api::as($contexto['token'])->get('/api/v1/me/workdays?'.$parametro.'='.$contexto['theirs']);

    $respuesta->assertStatus(422);

    expect($respuesta->getContent())->not->toContain($contexto['theirs']);
})->with([
    'employee_uuid' => 'employee_uuid',
    'uuid' => 'uuid',
    'employee' => 'employee',
    'employee_uuid como lista' => 'employee_uuid[]',
])->group('RF-ID-07', 'RQ-07', 'RL-05');

it('rechaza como campo desconocido un identificador ajeno en el cuerpo de /me/workdays', function (): void {
    $contexto = dosEmpleadosYUnPortal();

    $respuesta = Api::as($contexto['token'])
        ->call('GET', '/api/v1/me/workdays', ['employee_uuid' => $contexto['theirs']]);

    $respuesta->assertStatus(422)->assertJsonStructure(['errors' => ['employee_uuid']]);

    expect($respuesta->getContent())->not->toContain($contexto['theirs']);
})->group('RF-ID-07', 'RQ-07');

it('devuelve el registro propio aunque una cabecera señale a otra persona', function (): void {
    // La cabecera no existe para el servidor: la respuesta es la del titular
    // del token.
    $contexto = dosEmpleadosYUnPortal();

    Api::as($contexto['token'])
        ->withHeaders(['X-Employee-Uuid' => $contexto['theirs']])
        ->get('/api/v1/me/workdays')
        ->assertStatus(200)
        ->assertJsonPath('employee_uuid', $contexto['mine']);
})->group('RF-ID-07', 'RQ-07');

it('no exporta el registro de otra persona aunque se señale en la consulta de /me/export', function (string $parametro, string $formato): void {
    // A6 y V4-PO-2 (2.3.0). `/me/export` es la ruta que entrega el fichero
    // completo, y el CSV es su formato por omision: la manipulacion de la
    // barrera 1 se prueba tambien aqui, y no solo sobre `/me/workdays`. Sin
    // `format` (el CSV por omision) y con `format=csv` explicito.
    $contexto = dosEmpleadosYUnPortal();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/me/export?from=2026-03-01&to=2026-03-31'.$formato.'&'.$parametro.'='.$contexto['theirs']);

    // Un parametro que no esta en el contrato se rechaza como campo
    // desconocido ANTES de leer nada: ni el fichero del otro ni el propio.
    $respuesta->assertStatus(422)->assertJsonStructure(['errors' => [$parametro]]);

    expect($respuesta->headers->get('Content-Disposition'))->toBeNull()
        ->and($respuesta->getContent())->not->toContain($contexto['theirs']);
})->with(['employee_uuid', 'uuid', 'employee'])->with([
    'CSV por omision' => '',
    'CSV explicito' => '&format=csv',
])->group('RF-ID-07', 'RQ-07', 'RL-05');

it('exporta solo lo propio aunque el identificador ajeno viaje en el cuerpo o en una cabecera', function (): void {
    // Las otras dos vias de la barrera 1 sobre `/me/export` (A6). El campo en el
    // cuerpo se rechaza como desconocido; la cabecera no existe para el servidor
    // y la descarga es la del titular del token, sin una linea del otro.
    $contexto = dosEmpleadosYUnPortal();

    // Una jornada de cada uno, a horas distintas: si la del otro apareciera en
    // el fichero, sus 07:15 lo delatarian aunque el UUID no viajara en el CSV.
    aislamientoJornada($contexto['site'], $contexto['mine'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    aislamientoJornada($contexto['site'], $contexto['theirs'], '2026-03-14', '2026-03-14 07:15', '2026-03-14 15:45');

    Api::as($contexto['token'])
        ->call('GET', '/api/v1/me/export', ['employee_uuid' => $contexto['theirs']])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['employee_uuid']]);

    $descarga = Api::as($contexto['token'])
        ->withHeaders(['X-Employee-Uuid' => $contexto['theirs']])
        ->get('/api/v1/me/export?from=2026-03-01&to=2026-03-31');

    $descarga->assertStatus(200)->assertHeader('Content-Type', 'text/csv; charset=utf-8');

    $csv = $descarga->streamedContent();

    expect($csv)->toContain('2026-03-14 06:00')
        ->and($csv)->not->toContain($contexto['theirs'])
        ->and($csv)->not->toContain('2026-03-14 07:15')
        ->and($csv)->not->toContain('15:45');
})->group('RF-ID-07', 'RQ-07', 'RL-05');

it('no atiende un identificador colgado como segmento extra de /me', function (string $ruta): void {
    // El «segmento extra» que promete el docblock de este fichero (R4-QA-02):
    // la ruta no tiene `{uuid}`, asi que `/me/export/<ajeno>` no es una ruta.
    $contexto = dosEmpleadosYUnPortal();

    Api::as($contexto['token'])->get($ruta.'/'.$contexto['theirs'])->assertStatus(404);
})->with([
    'mi registro' => '/api/v1/me/workdays',
    'mi exportacion' => '/api/v1/me/export',
])->group('RF-ID-07', 'RQ-07');

it('no deja que una sesion de portal alcance el registro de otro por la ruta de gestion', function (): void {
    // La barrera 2 en su forma mas directa: la ruta de gestion existe y sirve
    // exactamente el dato que aqui hay que negar. Le falta `attendance:read` al
    // token del portal, asi que ni llega a la policy.
    $contexto = dosEmpleadosYUnPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['theirs'].'/workdays')
        ->assertStatus(403);

    // Ni siquiera el suyo propio: esa ruta es la de gestion, y el `self` del
    // Anexo B se sirve por `/me/workdays`.
    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['mine'].'/workdays')
        ->assertStatus(403);
})->group('RF-ID-07', 'RQ-07', 'RS-04');

it('no deja que una sesion de portal alcance ningun endpoint de gestion', function (string $uri): void {
    // El resto de la barrera 2. `self:read` es un ambito de lectura de lo
    // propio: no abre la plantilla, ni las credenciales, ni las correcciones, ni
    // la exportacion legal.
    $contexto = dosEmpleadosYUnPortal();

    Api::as($contexto['token'])->get($uri)->assertStatus(403);
})->with([
    'plantilla' => '/api/v1/employees',
    'ficha de otro' => '/api/v1/employees/00000000-0000-7000-8000-000000000000',
    'credenciales' => '/api/v1/credentials/status',
    'centro' => '/api/v1/site',
    'exportacion legal' => '/api/v1/reports/legal-export?from=2026-03-01&to=2026-03-31',
    // La vista de cumplimiento (tarea 3.4, RF-PA-06). Entra en la lista porque
    // reparte justo lo que `self:read` no concede: quien de la plantilla ha
    // incumplido el convenio, con nombre y departamento. Un empleado consulta lo
    // suyo por `/me/workdays`, y de esta pantalla no tiene ninguna version
    // «la mia».
    'vista de cumplimiento' => '/api/v1/compliance/summary',
])->group('RF-ID-07', 'RQ-07', 'RS-04');

it('no deja entrar por /me a ningun rol de gestion', function (string $role): void {
    // La barrera 3, y la que el enunciado de la tarea señala como imprescindible:
    // `/me/*` es EXCLUSIVAMENTE de sesion de portal. Un token de panel no entra
    // aunque su portador sea administrador — su registro, si lo tiene, lo
    // consulta con su sesion de portal como todo el mundo, y el de los demas por
    // la ruta de gestion, que queda auditada como divulgacion (RS-05).
    dosEmpleadosYUnPortal();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(403);
    Api::as($token)->get('/api/v1/me/export')->assertStatus(403);
})->with([
    'admin' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
    'auditor' => UserRole::AUDITOR->value,
    'responsable de departamento' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
    'cuenta con rol empleado' => UserRole::EMPLEADO->value,
])->group('RF-ID-07', 'RQ-07');

it('no deja entrar por /me a un quiosco', function (): void {
    // RS-04. El token de una tablet vive colgado de una pared: sus tres ambitos
    // no incluyen `self:read`, y si lo incluyeran, un quiosco robado leeria el
    // registro horario de alguien.
    dosEmpleadosYUnPortal();

    $quiosco = AttendanceFixtures::kioskToken();

    Api::as($quiosco)->get('/api/v1/me/workdays')->assertStatus(403);
    Api::as($quiosco)->get('/api/v1/me/export')->assertStatus(403);
})->group('RF-ID-07', 'RQ-07', 'RS-04', 'RF-ID-04');

it('no deja entrar por /me sin token', function (string $uri): void {
    // La mitad de la regla dura 18 que mas se olvida: comprobar tambien que sin
    // credenciales no se entra.
    dosEmpleadosYUnPortal();

    Api::guest()->get($uri)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with([
    'mi registro' => '/api/v1/me/workdays',
    'mi exportacion' => '/api/v1/me/export',
])->group('RF-ID-07', 'RQ-07');

it('no deja entrar por /me con un token de gestion recortado a self:read', function (): void {
    // El caso que separa la policy del ambito. Un token de panel al que alguien
    // le pusiera `self:read` a mano pasa el middleware `ability` y aun asi
    // recibe `403`: su `tokenable` es una cuenta de `users`, no una persona de
    // la plantilla. Sin esta prueba, la policy podria borrarse sin que fallara
    // nada.
    dosEmpleadosYUnPortal();

    $usuaria = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $usuaria->createToken('Sesion con el ambito equivocado', [
        TokenAbility::SELF_READ->value,
    ])->plainTextToken;

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(403);
})->group('RF-ID-07', 'RQ-07', 'RS-04');

it('no escribe un asiento de divulgacion cuando alguien consulta lo suyo', function (): void {
    // RS-05 registra el acceso a datos personales **de terceros**, y aqui no hay
    // tercero. Un apunte por cada consulta convertiria el derecho del art. 34.9
    // ET en una traza de su ejercicio, guardada cuatro años (RL-02) y
    // consultable por el empleador.
    $contexto = dosEmpleadosYUnPortal();

    Api::as($contexto['token'])->get('/api/v1/me/workdays')->assertStatus(200);
    Api::as($contexto['token'])->get('/api/v1/me/export')->assertStatus(200);

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(0);
})->group('RS-05', 'RF-ID-05');

it('sigue dejando asiento cuando quien consulta es gestion', function (): void {
    // El contrapunto del caso anterior: sin esto, un `if` demasiado ancho
    // apagaria tambien la constancia que RS-05 si exige.
    $contexto = dosEmpleadosYUnPortal();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->get('/api/v1/employees/'.$contexto['theirs'].'/workdays')->assertStatus(200);

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(1);
})->group('RS-05', 'RF-PA-03');
