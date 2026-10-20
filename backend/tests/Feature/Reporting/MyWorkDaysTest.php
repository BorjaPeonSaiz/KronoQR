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
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Reporting\BreakFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/me/workdays` y `GET /api/v1/me/export` — el registro propio y su
 * descarga, extremo a extremo y contra el contrato (RF-ID-05, RL-05, art. 34.9
 * ET, tarea 1.11).
 *
 * **La consulta es la misma que la del panel** y no una segunda: lo que cambia
 * es quien puede pedirla y sobre quien. Por eso aqui no se vuelven a probar la
 * agregacion de tramos ni los totales —eso lo hacen las unitarias de
 * `Reporting/Domain` y `tests/Feature/Reporting/EmployeeWorkDaysTest.php`— sino
 * lo que solo ocurre por esta puerta:
 *
 *   - Que el `uuid` sale del token y la respuesta es la del titular.
 *   - Que la suma cuadra tambien en el CSV que la persona se lleva.
 *   - Que el fichero no contiene a nadie mas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
});

/**
 * Centro en Madrid, un empleado con su portal abierto y el reloj detenido.
 *
 * @return array{token: string, site: int, employee: string}
 */
function miPortal(): array
{
    $site = WorkforceFixtures::site('Hotel de mi registro');
    $employee = WorkforceFixtures::employee($site);

    // La sesion se abre ANTES de detener el reloj, y no es un detalle de orden:
    // la caducidad del token la comprueba Sanctum contra el reloj real, asi que
    // un token emitido con un `Clock` fijado en marzo de 2026 nacería caducado
    // el dia que se ejecute la suite. Lo que se quiere congelar es el «hoy» de
    // la CONSULTA, no el de la sesion.
    $token = PortalLogins::open($employee);

    // Un instante fijo dentro del mes de los escenarios: sin esto, el rango por
    // omision —los 31 dias que terminan hoy— dependeria del dia en que se
    // ejecute la suite (regla dura 2).
    FrozenTime::at('2026-03-20 09:00:00');

    return [
        'token' => $token,
        'site' => $site,
        'employee' => $employee,
    ];
}

/**
 * Una jornada ya registrada por el quiosco, con su proyeccion recalculada.
 *
 * Se escribe con el agregado y su repositorio, no fichando: lo que estas pruebas
 * ejercitan es la CONSULTA.
 */
function miJornada(int $site, string $employee, string $workDate, string $entrada, ?string $salida): void
{
    $repositorio = app(WorkDayRepository::class);
    $proyector = app(DailyTotalsProjector::class);

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($workDate, Instants::madrid()));
    $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($entrada), ScanOrigin::QR_KIOSK);

    if ($salida !== null) {
        $jornada->clockOut(Instants::inMadrid($salida), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    }

    $repositorio->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }
}

it('devuelve el registro del titular del token, con la misma forma que el del panel', function (): void {
    $contexto = miPortal();

    miJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('employee_uuid', $contexto['employee'])
        ->assertJsonPath('time_zone', 'Europe/Madrid')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.work_date', '2026-03-14')
        // RN-06: el total del dia es exactamente la suma de sus tramos. Ocho
        // horas escritas como numero, no calculadas por la prueba.
        ->assertJsonPath('data.0.total_minutes', 480)
        // Regla dura 3: el instante en UTC **y** en la zona del centro, con el
        // desplazamiento escrito. El navegador no convierte nada.
        ->assertJsonPath('data.0.shift_entries.0.clocked_in_at', '2026-03-14T05:00:00.000000Z')
        ->assertJsonPath('data.0.shift_entries.0.clocked_in_at_local', '2026-03-14T06:00:00.000000+01:00');
})->group('RF-ID-05', 'RL-05', 'RN-06');

it('sirve el registro propio con no-store, como la descarga', function (): void {
    // V4-PO-1 (2.3.0). El portal se abre en el ordenador compartido de la sala
    // de personal: con el `no-cache, private` por omision de Laravel, el
    // navegador podia guardar en su cache de disco las horas de quien acababa
    // de salir. `assertValidResponse` comprueba ademas que el contrato declara
    // la cabecera.
    $contexto = miPortal();

    miJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', 'no-store, private');

    // Y vale tambien cuando no hay nada que enseñar: un rango vacio dice que esa
    // persona no trabajo esos dias, que tambien es un dato suyo.
    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-03-01', 'to' => '2026-03-02'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($respuesta->headers->get('Cache-Control'))->not->toContain('no-cache');
})->group('RF-ID-05', 'RL-05', 'RS-09');

it('dice que dia es hoy en la zona del centro, no en UTC', function (string $instanteUtc, string $hoy): void {
    // 2.3.0, bloque 8: el portal deja de pedir el registro sin rango solo para
    // saber que dia es. «Hoy» sale del `Clock` y de la zona del centro (reglas
    // duras 2 y 3), y no depende del rango pedido.
    $contexto = miPortal();
    FrozenTime::at($instanteUtc);

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-03-01', 'to' => '2026-03-02'])
        ->assertValidResponse(200)
        ->assertJsonPath('today', $hoy)
        ->assertJsonPath('to', '2026-03-02');
})->with([
    // Madrid en invierno (+01:00): en UTC aun es el 28, en el centro ya el 29.
    'pasada la medianoche local' => ['2026-03-28 23:30:00', '2026-03-29'],
    // El 29 de marzo de 2026 Madrid pasa a +02:00. A las 22:30 UTC son las
    // 00:30 del 30; con el desplazamiento de invierno serian las 23:30 del 29.
    'tras el cambio de hora de primavera' => ['2026-03-29 22:30:00', '2026-03-30'],
    // Y un minuto antes de la medianoche local sigue siendo el dia anterior.
    'antes de la medianoche local, ya en horario de verano' => ['2026-03-29 21:59:00', '2026-03-29'],
])->group('RF-ID-05', 'RN-04');

it('lleva today tambien en la vista de gestion, que comparte el esquema', function (): void {
    // `EmployeeWorkDays` es el mismo esquema para el panel y el portal: el campo
    // es obligatorio en los dos.
    $contexto = miPortal();
    FrozenTime::at('2026-03-28 23:30:00');

    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($rrhh)
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-01', 'to' => '2026-03-02'])
        ->assertValidResponse(200)
        ->assertJsonPath('today', '2026-03-29');
})->group('RF-PA-03', 'RN-04');

it('abre en los 31 dias que terminan hoy cuando no se pide rango', function (): void {
    // «Hoy» resuelto en la zona del CENTRO y con el reloj inyectado, no con el
    // del servidor ni con el del navegador (RN-04, regla dura 2).
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays')
        ->assertValidResponse(200)
        ->assertJsonPath('to', '2026-03-20')
        ->assertJsonPath('from', '2026-02-18');
})->group('RF-ID-05', 'RN-04');

it('mantiene entero el turno de noche en el registro propio', function (): void {
    // Regla dura 4 y RN-05: un 22:00 -> 06:00 es UN tramo, en la jornada en la
    // que empezo. Pedir el dia siguiente no devuelve medio tramo.
    $contexto = miPortal();

    miJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 22:00', '2026-03-15 06:00');

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-03-14', 'to' => '2026-03-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.work_date', '2026-03-14')
        ->assertJsonPath('data.0.shift_count', 1)
        ->assertJsonPath('data.0.total_minutes', 480);
})->group('RF-ID-05', 'RN-05');

it('devuelve un rango vacio cuando no se trabajo, y no un 404', function (): void {
    // No haber trabajado esos dias es una respuesta. Un `404` haria pensar a la
    // persona que su registro no existe.
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-01-01', 'to' => '2026-01-31'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
})->group('RF-ID-05', 'RL-05');

it('rechaza un rango imposible con 422 y el campo señalado', function (array $rango): void {
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays', $rango)
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->with([
    'invertido' => [['from' => '2026-03-31', 'to' => '2026-03-01']],
    'fecha que no existe' => [['from' => '2026-02-30', 'to' => '2026-03-01']],
    'mas ancho que el techo' => [['from' => '2024-01-01', 'to' => '2026-03-01']],
])->group('RF-ID-05', 'RQ-06');

it('descarga el historico propio en CSV con la suma cuadrando', function (): void {
    // RL-05 y RL-03: «capacidad de entrega inmediata». El fichero tiene que
    // poder abrirse en cualquier sitio y decir lo mismo que la pantalla.
    $contexto = miPortal();

    miJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    miJornada($contexto['site'], $contexto['employee'], '2026-03-16', '2026-03-16 07:00', '2026-03-16 15:30');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'csv']);

    $respuesta->assertStatus(200)
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        // El cuerpo es el registro horario de una persona: ni un proxy ni un
        // navegador compartido pueden guardarlo.
        ->assertHeader('Cache-Control', 'no-store, private');

    $csv = $respuesta->streamedContent();

    expect($csv)->toStartWith("\u{FEFF}")
        // `HH:MM` y nunca decimal: «8» y «8,5» son cifras que hay que
        // interpretar; `08:00` no se interpreta.
        ->and($csv)->toContain('08:00')
        ->and($csv)->toContain('08:30')
        ->and($csv)->not->toContain('8,5')
        // Las horas locales del centro, ademas de las de UTC.
        ->and($csv)->toContain('2026-03-14 06:00')
        ->and($csv)->toContain('2026-03-14T05:00:00Z')
        // Y el nombre del fichero no lleva ningun dato de nadie.
        ->and($respuesta->headers->get('Content-Disposition'))
        ->toBe('attachment; filename=mi-registro-horario-2026-03-01_2026-03-31.csv');
})->group('RF-ID-05', 'RL-05', 'RL-03');

it('dice en X-Kronoqr-Report-Rows cuantas filas de datos lleva el CSV, y 0 en un periodo vacio', function (): void {
    // 2.3.0, bloque 8: el portal lee la cabecera para decir que un periodo no
    // tiene jornadas en vez de anunciar una descarga vacia. Cuenta filas de
    // DATOS: la cabecera de criterios y los rotulos van siempre.
    $contexto = miPortal();

    miJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    miJornada($contexto['site'], $contexto['employee'], '2026-03-16', '2026-03-16 07:00', '2026-03-16 15:30');

    $conDatos = Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertHeader('X-Kronoqr-Report-Rows', '2');

    // Las dos filas que dice la cabecera son las dos que hay en el fichero.
    expect(substr_count($conDatos->streamedContent(), "\r\nTRAMO;"))->toBe(2);

    $vacio = Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-02-01', 'to' => '2026-02-28', 'format' => 'csv'])
        ->assertValidResponse(200)
        ->assertHeader('X-Kronoqr-Report-Rows', '0');

    // Y aun asi es un fichero: lleva su cabecera de criterios.
    expect($vacio->streamedContent())->toStartWith("\u{FEFF}")
        ->and($vacio->streamedContent())->not->toContain('TRAMO;');
})->group('RF-ID-05', 'RL-05', 'RL-03');

it('no mete a nadie mas en el fichero del historico propio', function (): void {
    // El alcance del fichero es exactamente el del token que lo pidio. Sin esta
    // prueba, un `JOIN` de mas en la consulta pasaria desapercibido hasta que
    // alguien abriera el CSV.
    $contexto = miPortal();
    $otro = WorkforceFixtures::employee($contexto['site']);

    miJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    miJornada($contexto['site'], $otro, '2026-03-14', '2026-03-14 07:00', '2026-03-14 15:00');

    $csv = Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->streamedContent();

    expect($csv)->not->toContain($otro)
        // La jornada del otro empezo a las 07:00: si apareciera, esta linea lo
        // caza aunque el UUID no viajara en el fichero.
        ->and($csv)->not->toContain('2026-03-14 07:00');
})->group('RF-ID-07', 'RL-05');

it('no ofrece ningun formato que no sea CSV o PDF', function (): void {
    // XLSX no esta previsto para el historico personal (Anexo B: CSV y PDF; el
    // PDF lo prueba `MyWorkDaysPdfTest`). Servir un CSV a quien pidio otra cosa
    // seria peor que decirle que no.
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['format' => 'xlsx'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['format' => 'json'])
        ->assertStatus(422);
})->group('RF-ID-05', 'RQ-06');

it('descarga el ultimo año, 366 dias, cuando no se pide rango', function (): void {
    // V3-PO-2 (2.2.1), decision del propietario del 09-10-2026: «en blanco
    // descarga el ultimo año». Hoy es el 20 de marzo de 2026 en Madrid, asi que
    // el rango empieza el 20 de marzo de 2025 —366 dias contandolo a el— y
    // NO el 18 de febrero, que es el mes de la pantalla.
    $contexto = miPortal();

    // El primer dia del año descargado entra, el anterior no.
    miJornada($contexto['site'], $contexto['employee'], '2025-03-20', '2025-03-20 06:00', '2025-03-20 14:00');
    miJornada($contexto['site'], $contexto['employee'], '2025-03-19', '2025-03-19 07:15', '2025-03-19 15:15');

    $respuesta = Api::as($contexto['token'])->get('/api/v1/me/export');

    $respuesta->assertValidRequest()
        ->assertStatus(200)
        ->assertHeader(
            'Content-Disposition',
            'attachment; filename=mi-registro-horario-2025-03-20_2026-03-20.csv',
        );

    $csv = $respuesta->streamedContent();

    expect($csv)->toContain('2025-03-20 06:00')
        ->and($csv)->not->toContain('2025-03-19 07:15');
})->group('RF-ID-05', 'RL-05', 'RN-04');

it('descarga los 366 dias que terminan en `to` cuando solo viene `to`', function (): void {
    // Sin `from`, el año termina en la fecha pedida y no en hoy.
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['to' => '2025-12-31'])
        ->assertValidRequest()
        ->assertStatus(200)
        ->assertHeader(
            'Content-Disposition',
            'attachment; filename=mi-registro-horario-2024-12-31_2025-12-31.csv',
        );
})->group('RF-ID-05', 'RL-05');

it('no cambia el mes por omision de la pantalla al cambiar el de la descarga', function (): void {
    // El valor por omision de la descarga se separo del de `/me/workdays`
    // (V3-PO-2): la pantalla sigue abriendo en los 31 dias que terminan hoy.
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/workdays')
        ->assertValidResponse(200)
        ->assertJsonPath('from', '2026-02-18')
        ->assertJsonPath('to', '2026-03-20');
})->group('RF-ID-05', 'RN-04');

it('sigue rechazando con 422 una descarga de 367 dias', function (string $formato): void {
    // «Como maximo un año por descarga»: el valor por omision llega al techo,
    // no lo mueve. Del 20 de marzo de 2025 al 21 de marzo de 2026 hay 367 dias.
    $contexto = miPortal();

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2025-03-20', 'to' => '2026-03-21', 'format' => $formato])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->with(['csv', 'pdf'])->group('RF-ID-05', 'RL-05', 'RQ-06');

it('enseña la pausa tambien en el registro que el empleado consulta', function (): void {
    // RF-AT-12 y RF-ID-05. Quien mas necesita saber que aquel hueco fue un
    // descanso y no una salida es la persona a la que se le van a pagar esas
    // horas: el portal comparte el esquema `WorkDayShiftEntry` con el panel a
    // proposito, y las dos marcas viajan tambien por esta puerta.
    $portal = miPortal();

    BreakFixtures::dayWithBreak(
        $portal['site'],
        $portal['employee'],
        '2026-03-14',
        '2026-03-14 06:00',
        '2026-03-14 10:00',
        '2026-03-14 10:30',
        '2026-03-14 14:00',
    );

    Api::as($portal['token'])
        ->get('/api/v1/me/workdays', ['from' => '2026-03-14', 'to' => '2026-03-14'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.shift_entries.0.opened_by', 'clock_in')
        ->assertJsonPath('data.0.shift_entries.0.closed_by', 'break_start')
        ->assertJsonPath('data.0.shift_entries.1.opened_by', 'break_end')
        ->assertJsonPath('data.0.shift_entries.1.closed_by', 'clock_out')
        ->assertJsonPath('data.0.total_minutes', 450);
})->group('RF-AT-12', 'RF-ID-05', 'RF-PA-03');
