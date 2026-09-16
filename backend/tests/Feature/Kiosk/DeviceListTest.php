<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La pantalla «Quioscos» del panel: `GET /api/v1/devices` con el veredicto de
 * salud y su `meta` (**RF-PA-07**, RF-PD-06, tarea 3.3).
 *
 * ## Que se afirma, y por que hacia falta una suite propia
 *
 * Hasta la tarea 3.3 este endpoint no tenia ni una prueba Feature: se cubria de
 * refilon en `AuthorizationNegativeTest` —un `200` y nada mas— y en el control
 * positivo del emparejamiento. Con la salud calculada en el servidor, la forma
 * de la respuesta ES el producto: de ella salen el color de cada fila, la
 * antiguedad que el panel extrapola y la leyenda con los umbrales.
 *
 * ## EL RELOJ SE DETIENE, y sin eso esta suite no significaria nada
 *
 * `seconds_since_last_seen` y las fronteras de los dos plazos solo se pueden
 * afirmar al segundo con el reloj parado (regla dura 2, ADR-021). Se usa
 * `FrozenTime::at()`, que es lo unico que detiene a la vez el puerto `Clock` y
 * el Carbon del framework —del que depende la caducidad del token de Sanctum—:
 * una prueba que instalara el reloj a mano se rompe sola cuando pasa el
 * calendario, que es como `main` se puso en rojo el 13-09.
 *
 * ## Contra el contrato
 *
 * `assertValidResponse()` en cada caso: de `openapi.yaml` se genera el cliente
 * TypeScript del panel, y una desviacion aqui rompe la pantalla sin que nadie se
 * entere en esta suite (RQ-06, ADR-013).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/** El token de la unica cuenta que puede ver la flota: `admin` con `settings:*` (§7.3 nota 5). */
function tokenDeFlota(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/**
 * Un quiosco con la telemetria que se le indique, escrita directamente.
 *
 * No pasa por el latido a proposito: montar «hace tres horas que no habla» con
 * peticiones reales exigiria mover el reloj entre ellas, y lo que esta suite
 * comprueba es como se LEE ese estado, no como se escribe —eso lo cubre
 * `HeartbeatTelemetryTest`—.
 *
 * @param  array<string, mixed>  $telemetry
 */
function quioscoEnEstado(string $name, array $telemetry, string $status = 'active'): string
{
    $device = AttendanceFixtures::device(WorkforceFixtures::site('Hotel de quioscos', 'Europe/Madrid'), $name);

    DB::table('devices')->where('id', $device['id'])->update([
        'name' => $name,
        'status' => $status,
        'app_version' => '2.2.0',
        'paired_at' => '2026-09-16 06:00:00+00',
        ...$telemetry,
    ]);

    return $device['uuid'];
}

// --- La forma de la respuesta -----------------------------------------------

it('sirve cada quiosco con su telemetria y su veredicto', function (): void {
    FrozenTime::at('2026-09-16 12:00:00');

    $uuid = quioscoEnEstado('Recepcion', [
        'last_seen_at' => '2026-09-16 11:59:30+00',
        'pending_queue_size' => 0,
        'battery_level' => 83,
        'battery_charging' => true,
        'oldest_pending_at' => null,
    ]);

    $respuesta = Api::as(tokenDeFlota())->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse();

    $respuesta->assertJsonPath('devices.0.uuid', $uuid)
        ->assertJsonPath('devices.0.name', 'Recepcion')
        ->assertJsonPath('devices.0.status', 'active')
        ->assertJsonPath('devices.0.battery_level', 83)
        ->assertJsonPath('devices.0.battery_charging', true)
        ->assertJsonPath('devices.0.oldest_pending_at', null)
        ->assertJsonPath('devices.0.health.verdict', 'ok')
        ->assertJsonPath('devices.0.health.reason', 'beating')
        // El reloj esta parado: 30 s exactos, no «unos 30».
        ->assertJsonPath('devices.0.health.seconds_since_last_seen', 30);

    // Y NUNCA el token ni la clave interna (regla dura 21): quien viera el hash
    // tendria la mitad del trabajo hecho para suplantar a un dispositivo.
    /** @var array<string, mixed> $device */
    $device = $respuesta->json('devices.0');

    expect($device)->not->toHaveKey('token_hash')
        ->and($device)->not->toHaveKey('id')
        ->and($device)->not->toHaveKey('site_id');
})->group('RF-PA-07', 'RF-PD-06');

it('devuelve el reloj del servidor, la zona del centro y los umbrales reales', function (): void {
    // `meta` es lo que impide que el panel mida con el reloj del navegador y
    // suponga el umbral de la alerta (decision 3). Los tres campos son
    // obligatorios en el contrato.
    FrozenTime::at('2026-09-16 12:00:00');
    quioscoEnEstado('Recepcion', ['last_seen_at' => '2026-09-16 11:59:30+00']);

    Api::as(tokenDeFlota())->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('meta.generated_at', '2026-09-16T12:00:00.000Z')
        // La del centro, no `APP_TIMEZONE`: es la zona en la que el panel
        // escribe los instantes que viajan en UTC (regla dura 3).
        ->assertJsonPath('meta.timezone', 'Europe/Madrid')
        // Los mismos numeros que `kiosk:health --json` y que la alerta
        // «Quiosco sin latido > 10 min» del doc 01 §9.3.
        ->assertJsonPath('meta.thresholds.fresh_within_seconds', 120)
        ->assertJsonPath('meta.thresholds.silent_after_seconds', 600)
        ->assertJsonPath('meta.thresholds.battery_low_percent', 15);
})->group('RF-PA-07');

// --- El veredicto, que es lo que pinta el panel -----------------------------

it('juzga cada quiosco con la misma regla que la consola y la alerta', function (
    array $telemetry,
    string $verdict,
    string $reason,
): void {
    FrozenTime::at('2026-09-16 12:00:00');
    quioscoEnEstado('Recepcion', $telemetry);

    Api::as(tokenDeFlota())->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('devices.0.health.verdict', $verdict)
        ->assertJsonPath('devices.0.health.reason', $reason);
})->with([
    'al dia' => [['last_seen_at' => '2026-09-16 11:59:30+00'], 'ok', 'beating'],
    'con cola' => [
        ['last_seen_at' => '2026-09-16 11:59:30+00', 'pending_queue_size' => 7],
        'warning',
        'queue_pending',
    ],
    'atrasado' => [['last_seen_at' => '2026-09-16 11:55:00+00'], 'warning', 'late'],
    // 600 s es el umbral de la alerta critica del doc 01 §9.3: el mismo numero.
    'callado' => [['last_seen_at' => '2026-09-16 11:30:00+00'], 'failure', 'silent'],
    'sin latido nunca' => [['last_seen_at' => null], 'failure', 'never_seen'],
    'bateria baja y sin cargar' => [
        ['last_seen_at' => '2026-09-16 11:59:30+00', 'battery_level' => 9, 'battery_charging' => false],
        'warning',
        'battery_low',
    ],
    'bateria baja pero cargando' => [
        ['last_seen_at' => '2026-09-16 11:59:30+00', 'battery_level' => 9, 'battery_charging' => true],
        'ok',
        'beating',
    ],
])->group('RF-PA-07');

it('da por revocado un quiosco desvinculado, sin contarlo como averia', function (): void {
    // Un desvinculado no late porque no debe. Contarlo como fallo llenaria de
    // rojo el panel de cualquier hotel que haya sustituido una tablet alguna vez.
    FrozenTime::at('2026-09-16 12:00:00');
    quioscoEnEstado('Cocina vieja', ['last_seen_at' => '2026-08-01 10:00:00+00'], status: 'revoked');

    Api::as(tokenDeFlota())->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('devices.0.status', 'revoked')
        ->assertJsonPath('devices.0.health.verdict', 'revoked')
        ->assertJsonPath('devices.0.health.reason', 'revoked');
})->group('RF-PA-07');

it('no devuelve una antiguedad negativa con un latido del futuro', function (): void {
    // Un `pg_restore` de una copia hecha en otra maquina, o un salto de NTP
    // hacia atras, dejan un instante por delante del reloj. «hace -12 s» en el
    // panel haria dudar de la pantalla entera justo cuando se abre para
    // diagnosticar algo.
    FrozenTime::at('2026-09-16 12:00:00');
    quioscoEnEstado('Recepcion', ['last_seen_at' => '2026-09-16 12:05:00+00']);

    Api::as(tokenDeFlota())->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('devices.0.health.seconds_since_last_seen', 0)
        ->assertJsonPath('devices.0.health.verdict', 'ok');
})->group('RF-PA-07');

// --- El orden ---------------------------------------------------------------

it('pone los activos delante y ordena por nombre', function (): void {
    // Quien abre la pantalla busca el quiosco que no responde, no el que se dio
    // de baja el año pasado. El orden lo decide el servidor: si lo decidiera el
    // panel, la consola y la pantalla listarian distinto.
    FrozenTime::at('2026-09-16 12:00:00');

    quioscoEnEstado('Recepcion', ['last_seen_at' => '2026-09-16 11:59:30+00']);
    quioscoEnEstado('Almacen', ['last_seen_at' => '2026-09-16 11:59:30+00']);
    quioscoEnEstado('Cocina vieja', ['last_seen_at' => '2026-08-01 10:00:00+00'], status: 'revoked');

    $respuesta = Api::as(tokenDeFlota())->get('/api/v1/devices')->assertOk()->assertValidResponse();

    /** @var list<array{name: string}> $devices */
    $devices = $respuesta->json('devices');

    expect(array_column($devices, 'name'))->toBe(['Almacen', 'Recepcion', 'Cocina vieja']);
})->group('RF-PA-07', 'RF-PD-06');

it('devuelve la lista vacia y su meta en una instalacion sin quioscos', function (): void {
    // Es el estado de una puesta en marcha. El panel tiene que poder pintar su
    // leyenda y su mensaje de «todavia no hay ninguno» sin un `meta` ausente.
    FrozenTime::at('2026-09-16 12:00:00');
    WorkforceFixtures::site('Hotel recien instalado', 'Atlantic/Canary');

    Api::as(tokenDeFlota())->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('devices', [])
        ->assertJsonPath('meta.timezone', 'Atlantic/Canary');
})->group('RF-PA-07');

// --- `unpair` devuelve el estado de DESPUES ---------------------------------

it('devuelve el quiosco ya revocado, con su veredicto, al desvincularlo', function (): void {
    // Para que el panel repinte la fila sin volver a pedir la lista. Si
    // devolviera el estado de antes, la pantalla diria `active` sobre un quiosco
    // que acaba de dejar de serlo.
    FrozenTime::at('2026-09-16 12:00:00');
    $uuid = quioscoEnEstado('Recepcion', ['last_seen_at' => '2026-09-16 11:59:30+00']);

    Api::as(tokenDeFlota())->post('/api/v1/devices/'.$uuid.'/unpair')
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('uuid', $uuid)
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('health.verdict', 'revoked')
        ->assertJsonPath('health.reason', 'revoked');
})->group('RF-PA-07', 'RF-PD-06');
