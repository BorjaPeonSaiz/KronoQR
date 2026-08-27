<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/employees/{uuid}/workdays` — el detalle de jornada del panel,
 * extremo a extremo y contra el contrato (RF-PA-03, tarea 1.16).
 *
 * Cada respuesta pasa por Spectator: el cliente TypeScript de los tres frontends
 * se genera de `openapi.yaml`, asi que una desviacion aqui rompe a los tres a la
 * vez y sin aviso.
 *
 * Lo que estas pruebas defienden, ademas de la forma de la respuesta:
 *
 *   - **Regla dura 4**: un turno 22:00 → 06:00 sale una sola vez y en la jornada
 *     en que empezo. Pedir el dia siguiente no devuelve medio tramo.
 *   - **Regla dura 3**: los instantes salen en UTC **y** en la zona del centro
 *     con el desplazamiento escrito. El cliente no adivina la zona.
 *   - **Regla dura 5**: los tramos anulados no desaparecen de la pantalla; la
 *     jornada sigue ahi con su historico.
 *   - **RN-06**: el total del dia es exactamente la suma de sus tramos.
 *   - **RS-05**: consultar el registro de un tercero deja constancia.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Centro en Madrid, un empleado y una sesion de RRHH, que es «manager+» en la
 * Fase 1.
 *
 * @return array{token: string, site: int, employee: string}
 */
function contextoDeJornadas(): array
{
    $site = WorkforceFixtures::site('Hotel de jornadas');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
    ];
}

/**
 * Una jornada ya registrada por el quiosco, con su proyeccion recalculada.
 *
 * Se escribe con el agregado y su repositorio, no con el endpoint de escaneo: lo
 * que estas pruebas ejercitan es la CONSULTA, y montar el estado previo fichando
 * obligaria a fabricar credenciales que no vienen al caso.
 *
 * Las horas se pasan en hora de reloj de Madrid, que es como estan enunciados los
 * escenarios: la conversion a UTC la hace {@see Instants}.
 */
function jornadaRegistrada(
    int $site,
    string $employee,
    string $workDate,
    string $entrada,
    ?string $salida,
): string {
    $repositorio = app(WorkDayRepository::class);
    $proyector = app(DailyTotalsProjector::class);

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($workDate, Instants::madrid()));
    $tramo = $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($entrada), ScanOrigin::QR_KIOSK);

    if ($salida !== null) {
        $jornada->clockOut(Instants::inMadrid($salida), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    }

    $repositorio->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }

    return $tramo->uuid();
}

it('devuelve las jornadas del rango, de la mas antigua a la mas reciente', function (): void {
    $contexto = contextoDeJornadas();

    jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-16', '2026-03-16 07:00', '2026-03-16 15:30');

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', [
            'from' => '2026-03-14',
            'to' => '2026-03-16',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('employee_uuid', $contexto['employee'])
        ->assertJsonPath('time_zone', 'Europe/Madrid')
        ->assertJsonPath('from', '2026-03-14')
        ->assertJsonPath('to', '2026-03-16')
        // El dia 15 no aparece: un dia libre no es una fila vacia, es la
        // ausencia de fila.
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.work_date', '2026-03-14')
        ->assertJsonPath('data.1.work_date', '2026-03-16')
        ->assertJsonPath('data.0.total_minutes', 480)
        ->assertJsonPath('data.1.total_minutes', 510)
        ->assertJsonPath('data.0.shift_count', 1)
        ->assertJsonPath('data.0.has_open_shift', false)
        ->assertJsonPath('data.0.has_incident', false);
})->group('RF-PA-03');

it('el total del dia es la suma de sus tramos, y con dos tramos tambien', function (): void {
    // RN-06 y el primer principio del panel: el dato tiene consecuencias. Si el
    // total no cuadrara con las filas que tiene debajo, la pantalla estaria
    // enseñando dos cifras distintas de lo mismo — y una acaba en una nomina.
    $contexto = contextoDeJornadas();

    $repositorio = app(WorkDayRepository::class);
    $proyector = app(DailyTotalsProjector::class);

    $jornada = WorkDay::start($contexto['employee'], $contexto['site'], WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 08:00'), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::inMadrid('2026-03-14 12:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 16:00'), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::inMadrid('2026-03-14 20:30'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.shift_count', 2)
        ->assertJsonPath('data.0.total_minutes', 240 + 270);

    /** @var array<int, int> $duraciones */
    $duraciones = $respuesta->json('data.0.shift_entries.*.duration_minutes');

    expect(array_sum($duraciones))->toBe($respuesta->json('data.0.total_minutes'));
})->group('RF-PA-03', 'RN-06');

it('devuelve un turno nocturno como un solo tramo, en la jornada en que empezo', function (): void {
    // RN-05, ADR-006 y regla dura 4. El filtro es por `work_date`, que es una
    // columna propia: pedir el dia 15 no puede devolver el tramo que empezo el 14
    // a las 22:00, ni partirlo en dos a medianoche.
    $contexto = contextoDeJornadas();

    jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 22:00', '2026-03-15 06:00');

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonCount(1, 'data.0.shift_entries')
        ->assertJsonPath('data.0.total_minutes', 480)
        ->assertJsonPath('data.0.shift_entries.0.duration_minutes', 480);

    // Y el dia siguiente esta vacio: ahi no ocurrio ninguna jornada, aunque el
    // reloj de esa persona marcara horas de ese dia.
    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-15', 'to' => '2026-03-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
})->group('RF-PA-03', 'RN-05', 'RF-AT-08');

it('presenta cada instante en UTC y ademas en la zona del centro', function (): void {
    // Regla dura 3. Las dos formas viajan: la de UTC es el dato y la local es lo
    // que se pinta. Si la conversion la hiciera el navegador, la haria con la
    // zona del dispositivo — y con turnos nocturnos eso es una hora mal escrita
    // en un registro legal.
    $contexto = contextoDeJornadas();

    jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 22:00', '2026-03-15 06:00');

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.time_zone', 'Europe/Madrid')
        ->assertJsonPath('data.0.shift_entries.0.time_zone', 'Europe/Madrid')
        ->assertJsonPath('data.0.shift_entries.0.clocked_in_at', '2026-03-14T21:00:00.000000Z')
        ->assertJsonPath('data.0.shift_entries.0.clocked_in_at_local', '2026-03-14T22:00:00.000000+01:00')
        ->assertJsonPath('data.0.shift_entries.0.clocked_out_at', '2026-03-15T05:00:00.000000Z')
        ->assertJsonPath('data.0.shift_entries.0.clocked_out_at_local', '2026-03-15T06:00:00.000000+01:00');
})->group('RF-PA-03', 'RN-04');

it('dice que hay un turno abierto y no le inventa duracion', function (): void {
    // RN-01. Un turno sin cerrar aporta CERO al total del dia: darle una
    // duracion seria dar por terminado lo que no ha terminado, y ese numero
    // acaba en una nomina.
    $contexto = contextoDeJornadas();

    jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', null);

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.has_open_shift', true)
        ->assertJsonPath('data.0.total_minutes', 0)
        ->assertJsonPath('data.0.shift_entries.0.status', 'open')
        ->assertJsonPath('data.0.shift_entries.0.duration_minutes', null)
        ->assertJsonPath('data.0.shift_entries.0.clocked_out_at', null)
        ->assertJsonPath('data.0.shift_entries.0.clocked_out_at_local', null);
})->group('RF-PA-03', 'RN-01');

it('trae el historico de correcciones con autor, motivo y las marcas de antes y de despues', function (): void {
    // RN-13, RL-04 y regla dura 5: nada se sobrescribe. La pantalla tiene que
    // poder pintar el «de → a» sin volver a consultar nada, y por eso la
    // correccion lleva las marcas COMPLETAS de las dos versiones.
    $contexto = contextoDeJornadas();
    $abierto = jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', null);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$abierto, [
            'clocked_out_at' => '2026-03-14T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertValidResponse(200);

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200);

    // Vigente hay UNA version, la corregida. La anterior no desaparecio: esta en
    // el historico, con lo que decia.
    $respuesta
        ->assertJsonCount(1, 'data.0.shift_entries')
        ->assertJsonPath('data.0.shift_entries.0.version', 2)
        ->assertJsonPath('data.0.shift_entries.0.status', 'closed')
        ->assertJsonCount(1, 'data.0.corrections')
        ->assertJsonPath('data.0.corrections.0.action', 'closed')
        ->assertJsonPath('data.0.corrections.0.reason_code', 'OLVIDO_FICHAJE_SALIDA')
        ->assertJsonPath('data.0.corrections.0.before.version', 1)
        ->assertJsonPath('data.0.corrections.0.before.clocked_out_at', null)
        ->assertJsonPath('data.0.corrections.0.before.worked_minutes', 0)
        ->assertJsonPath('data.0.corrections.0.after.version', 2)
        // 05:00Z → 14:00Z son nueve horas: el «de → a» del panel sale de estas
        // dos versiones y no de recomponer nada.
        ->assertJsonPath('data.0.corrections.0.after.worked_minutes', 540)
        ->assertJsonPath('data.0.total_minutes', 540);

    // Y quien firmo, que RN-13 obliga a poder enseñar: una correccion sin autor
    // no explica nada ante una inspeccion.
    expect($respuesta->json('data.0.corrections.0.performed_by.uuid'))->toBeString()
        ->and($respuesta->json('data.0.corrections.0.performed_by.name'))->toBeString();
})->group('RF-PA-03', 'RN-13', 'RL-04');

it('sigue enseñando la jornada cuyos tramos se anularon todos', function (): void {
    // Regla dura 5. Ocultarla haria desaparecer de la pantalla justo el dia que
    // alguien necesita explicar: el total baja a cero, pero el historico dice
    // por que y quien lo hizo.
    $contexto = contextoDeJornadas();
    $tramo = jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries/'.$tramo.'/void', ['reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO'])
        ->assertValidResponse(200);

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.shift_entries', [])
        ->assertJsonPath('data.0.total_minutes', 0)
        ->assertJsonPath('data.0.shift_count', 0)
        ->assertJsonCount(1, 'data.0.corrections')
        ->assertJsonPath('data.0.corrections.0.action', 'voided')
        ->assertJsonPath('data.0.corrections.0.after', null);
})->group('RF-PA-03', 'RN-13', 'RL-04');

it('sin rango devuelve los 31 dias que terminan hoy en la zona del centro', function (): void {
    // RN-04 y regla dura 2: «hoy» a las 00:30 de Madrid es todavia ayer en UTC.
    // Resolver la omision con la hora del servidor dejaria fuera la jornada en
    // curso justo en el turno de noche, que es cuando alguien mira esta pantalla.
    $contexto = contextoDeJornadas();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 23:30:00'));

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays')
        ->assertValidRequest()
        ->assertValidResponse(200)
        // 00:30 del 15 en Madrid: el rango termina el 15 y no el 14.
        ->assertJsonPath('to', '2026-03-15')
        ->assertJsonPath('from', '2026-02-13');
})->group('RF-PA-03', 'RN-04');

it('responde 404 por un empleado que no existe, y no una jornada en blanco', function (): void {
    // «Esta persona no existe» y «esta persona no trabajo esos dias» son dos
    // hechos distintos. Un 200 vacio para el primero enseñaria una pantalla
    // creible a quien escribio mal el identificador.
    $contexto = contextoDeJornadas();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-000000000000/workdays')
        ->assertValidResponse(404)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-found');
})->group('RF-PA-03');

it('rechaza un rango invertido, uno inexistente y uno mas ancho que el techo', function (): void {
    // El techo no es una regla de negocio: es lo que impide que una URL
    // manipulada pida el historico completo de una persona en una sola
    // respuesta. Quien necesite mas usa la exportacion, que se genera aparte.
    $contexto = contextoDeJornadas();
    $ruta = '/api/v1/employees/'.$contexto['employee'].'/workdays';

    Api::as($contexto['token'])
        ->get($ruta, ['from' => '2026-03-31', 'to' => '2026-03-01'])
        ->assertValidResponse(422);

    Api::as($contexto['token'])
        ->get($ruta, ['from' => '2026-02-30', 'to' => '2026-03-01'])
        ->assertValidResponse(422);

    Api::as($contexto['token'])
        ->get($ruta, ['from' => '2025-01-01', 'to' => '2026-03-01'])
        ->assertValidResponse(422);
})->group('RF-PA-03');

it('rechaza un parametro que no existe en lugar de ignorarlo', function (): void {
    // Un `?desde=2026-03-01` devolveria el mes por omision en silencio, y quien
    // lo escribio se iria convencido de estar mirando marzo.
    $contexto = contextoDeJornadas();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['desde' => '2026-03-01'])
        ->assertValidResponse(422);
})->group('RF-PA-03');

it('deja constancia del acceso en audit_log, con el alcance y sin nombres', function (): void {
    // RS-05: todo acceso a datos personales de terceros queda registrado. Se
    // apunta EL ALCANCE —de quien, que rango, cuantas jornadas— y nunca lo
    // divulgado: ni una hora, ni un nombre (regla dura 21).
    $contexto = contextoDeJornadas();
    jornadaRegistrada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    // La jornada se escribio por el repositorio, no por un caso de uso: en
    // `audit_log` no hay todavia ningun asiento.
    expect(DB::table('audit_log')->count())->toBe(0);

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{action: string, actor_type: string, subject_type: string, payload: string} $asiento */
    expect($asiento->action)->toBe('personal_data.accessed')
        ->and($asiento->actor_type)->toBe('user')
        ->and($asiento->subject_type)->toBe('personal_data')
        ->and($asiento->payload)->toContain('employee_workdays')
        ->and($asiento->payload)->toContain($contexto['employee'])
        ->and($asiento->payload)->toContain('2026-03-14')
        // El alcance, no el contenido: ni el nombre del empleado ni sus horas.
        ->and($asiento->payload)->not->toContain('Persona')
        ->and($asiento->payload)->not->toContain('De Prueba')
        ->and($asiento->payload)->not->toContain('06:00');
})->group('RF-PA-03', 'RS-05');
