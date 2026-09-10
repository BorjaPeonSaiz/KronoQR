<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Infrastructure\Metrics\RedisScanMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `scans_by_origin_total{origin}` **desde los endpoints de verdad** (RF-IN-08,
 * doc 02 §8.2, tarea 3.1).
 *
 * ## Por que esta prueba no se parece a las otras de metricas
 *
 * Las hermanas de `tests/Integration/Shared/MetricsExpositionTest.php`
 * comprueban el ADAPTADOR: que `RedisScanMetrics` escribe la etiqueta correcta
 * cuando alguien lo llama. Lo que ninguna de ellas puede afirmar es que **el
 * camino real lo llame**, y ese es justo el fallo que la tarea 3.1 vino a
 * arreglar: doce series escribiendose en Redis que nadie exponia, con todas sus
 * pruebas en verde.
 *
 * Por eso aqui NO se sustituye `ScanMetrics` por un doble —al reves que en
 * `RegisterScanTest` y en `PinScanOriginTest`, que hacen bien en usarlo porque
 * lo suyo es otra cosa—: se ficha contra los tres endpoints y se lee **el hash
 * de Redis que `/metrics` publica**. Con un doble, cualquier renombrado de la
 * serie o cualquier cableado perdido en el contenedor pasaria desapercibido.
 *
 * ## El reparto tiene que sumar lo que ocurrio en el hotel
 *
 * Es la metrica que sostiene la conversacion de la renovacion: *«¿la gente ficha
 * con su tarjeta, o esto funciona a base de PIN y de correcciones a mano?»*. Un
 * reenvio contado dos veces o una correccion contada como fichaje convierten esa
 * respuesta en una opinion.
 */

uses(RefreshDatabase::class);

/** La tarjeta que el resolutor doble acepta en este fichero. */
const TARJETA_DE_ORIGEN = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const PIN_DE_ORIGEN = '482913';

/** El reloj detenido de todas las pruebas de aqui (regla dura 2). */
const AHORA_DEL_ORIGEN = '2026-03-14 07:02:31';

beforeEach(function (): void {
    // La serie es un contador acumulado en Redis, que NO se vacia entre pruebas
    // como si lo hace la base de datos. Sin esto, cada prueba heredaria el
    // reparto de la anterior y las aserciones dependerian del orden.
    app(Redis::class)->connection()->command('DEL', [RedisScanMetrics::SCANS_BY_ORIGIN]);
});

afterEach(function (): void {
    app(Redis::class)->connection()->command('DEL', [RedisScanMetrics::SCANS_BY_ORIGIN]);
});

/**
 * Centro, empleado, quiosco y PIN, con el reloj detenido y la tarjeta declarada.
 *
 * **Sin doble de `ScanMetrics`**: la instalacion resuelve `RedisScanMetrics`,
 * que es lo que esta prueba viene a comprobar.
 *
 * @return array{site: int, employee: string, token: string, code: string, publicKey: string}
 */
function escenarioDeOrigenReal(): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], PIN_DE_ORIGEN);

    app()->instance(Clock::class, FixedClock::at(AHORA_DEL_ORIGEN));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DE_ORIGEN, $escenario['employee']),
    );

    return [
        'site' => $escenario['site'],
        'employee' => $escenario['employee'],
        'token' => $escenario['token'],
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
    ];
}

/**
 * @param  array{token: string, ...}  $escenario
 * @return TestResponse<Response>
 */
function escanearTarjeta(array $escenario, string $scanId, string $occurredAt = '2026-03-14T07:02:31Z'): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => TARJETA_DE_ORIGEN,
        ]);
}

/**
 * @param  array{token: string, code: string, publicKey: string, ...}  $escenario
 * @return TestResponse<Response>
 */
function escanearPin(array $escenario, string $scanId, ?string $code = null, string $occurredAt = '2026-03-14T07:02:31Z'): TestResponse
{
    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'employee_code' => $code ?? $escenario['code'],
            'pin_sealed' => EmployeePins::seal(PIN_DE_ORIGEN, $escenario['publicKey']),
        ]);
}

/**
 * Un segundo empleado del mismo centro, con el mismo PIN de laboratorio.
 *
 * Hace falta porque el anti-rebote de RF-AT-06 es por PERSONA: dos fichajes de
 * la misma en el mismo segundo son un rebote, no dos gestos, y el segundo no
 * cuenta. Dos personas fichando a la vez es, en cambio, lo que pasa en un
 * cambio de turno.
 */
function companeroDeTurno(int $site): string
{
    $employee = WorkforceFixtures::employee($site);

    EmployeePins::issue($employee, PIN_DE_ORIGEN);

    return EmployeePins::codeOf($employee);
}

/**
 * El reparto por origen tal y como esta en Redis, que es lo que `/metrics`
 * publica.
 *
 * @return array<string, string>
 */
function repartoPorOrigen(): array
{
    /** @var array<string, string> $fields */
    $fields = app(Redis::class)->connection()->command('HGETALL', [RedisScanMetrics::SCANS_BY_ORIGIN]);

    return $fields;
}

it('un fichaje con tarjeta suma al origen qr', function (): void {
    $escenario = escenarioDeOrigenReal();

    escanearTarjeta($escenario, Str::uuid7()->toString())->assertOk();

    expect(repartoPorOrigen())->toBe(['origin=qr' => '1']);
})->group('RF-IN-08');

it('un fichaje de respaldo por PIN suma al origen pin y no al de tarjeta', function (): void {
    // La razon de que el contador viva en el caso de uso y no en `ScanTelemetry`:
    // aquella es comun a los dos endpoints y habria etiquetado como `qr` todos
    // los fichajes por PIN, que es precisamente el numero que el hotel necesita
    // ver subir cuando las tarjetas dejan de funcionar.
    $escenario = escenarioDeOrigenReal();

    escanearPin($escenario, Str::uuid7()->toString())->assertOk();

    expect(repartoPorOrigen())->toBe(['origin=pin' => '1']);
})->group('RF-IN-08', 'RF-AT-11');

it('el alta manual de un tramo suma al origen manual', function (): void {
    // Un tramo que alguien teclea porque el empleado se dejo la tarjeta en casa
    // es una jornada que el sistema no capturo solo, y ahi es donde cuenta.
    $escenario = escenarioDeOrigenReal();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $escenario['employee'],
            'work_date' => '2026-03-14',
            'clocked_in_at' => '2026-03-14T06:00:00Z',
            'clocked_out_at' => '2026-03-14T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertCreated();

    expect(repartoPorOrigen())->toBe(['origin=manual' => '1']);
})->group('RF-IN-08', 'RF-PA-04');

it('corregir un tramo que ya existia no suma ningun origen', function (): void {
    // Ese fichaje ya se conto cuando ocurrio, con su origen de verdad. Contarlo
    // otra vez al rectificarlo haria que un hotel que corrige sus errores con
    // cuidado pareciera el que menos usa la tarjeta.
    $escenario = escenarioDeOrigenReal();
    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    escanearTarjeta($escenario, Str::uuid7()->toString())->assertOk();

    /** @var string $tramo */
    $tramo = DB::table('shift_entries')->value('uuid');

    Api::as($rrhh)
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertOk();

    // El fichaje de la tarjeta, y solo ese.
    expect(repartoPorOrigen())->toBe(['origin=qr' => '1']);
})->group('RF-IN-08', 'RF-PA-04');

it('un reenvio con el mismo scan_id no vuelve a contar', function (): void {
    // Regla dura 8 aplicada a las metricas. La cola offline del quiosco reintenta
    // ante fallo de red (RF-KI-04): sin esto, una tablet con mala cobertura
    // inflaria el reparto por origen con reintentos y el cuadro de adopcion
    // premiaria justo al peor dispositivo del hotel.
    $escenario = escenarioDeOrigenReal();
    $scanId = Str::uuid7()->toString();

    escanearTarjeta($escenario, $scanId)->assertOk();
    escanearTarjeta($escenario, $scanId)->assertOk();
    escanearTarjeta($escenario, $scanId)->assertOk();

    expect(repartoPorOrigen())->toBe(['origin=qr' => '1']);
})->group('RF-IN-08', 'RF-AT-07');

it('un escaneo rechazado no cuenta como fichaje', function (): void {
    // No produjo tramo, asi que no es una jornada registrada. Con los rechazos
    // dentro, el reparto dejaria de sumar lo que de verdad ocurrio.
    $escenario = escenarioDeOrigenReal();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan', [
            'scan_id' => Str::uuid7()->toString(),
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => 'FH1.a3.0000000000000000000000.0000000000000000',
        ]);

    expect(repartoPorOrigen())->toBe([]);
})->group('RF-IN-08', 'RS-03');

it('el reparto de los tres origenes sale por /metrics', function (): void {
    // El extremo del cable. Las tres etiquetas del doc 01 §9.2 en la misma serie
    // y con el formato de exposicion, que es lo que el cuadro de «Impacto y
    // adopcion» consulta.
    config(['observability.metrics.allow_cidr' => '10.91.0.0/24']);

    $escenario = escenarioDeOrigenReal();

    escanearTarjeta($escenario, Str::uuid7()->toString())->assertOk();
    escanearPin($escenario, Str::uuid7()->toString(), companeroDeTurno($escenario['site']))->assertOk();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $escenario['employee'],
            'work_date' => '2026-03-13',
            'clocked_in_at' => '2026-03-13T06:00:00Z',
            'clocked_out_at' => '2026-03-13T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertCreated();

    $cuerpo = (string) Api::guest()->fromIp('10.91.0.5')->get('/metrics')->getContent();

    expect($cuerpo)
        ->toContain('# TYPE scans_by_origin_total counter')
        ->toContain('scans_by_origin_total{origin="qr"} 1')
        ->toContain('scans_by_origin_total{origin="pin"} 1')
        ->toContain('scans_by_origin_total{origin="manual"} 1');
})->group('RF-IN-08', 'RS-09');
