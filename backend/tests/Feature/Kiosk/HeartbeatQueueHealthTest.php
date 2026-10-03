<?php

declare(strict_types=1);

use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Kiosk\RecordingKioskMetrics;

/*
 * **El estado de la cola en el latido y en la salud del quiosco** (RF-PA-07,
 * RF-KI-04, ADR-047), de punta a punta y contra el contrato.
 *
 * Una cola que cayo a memoria no se declara vacia: el latido manda
 * `pending_queue_size: null` con `queue_storage`, el servidor lo guarda tal
 * cual, el panel marca el quiosco como fallo y la metrica de tamaño se retira.
 * Una PWA anterior a la 2.2.0, que no manda ninguno de los dos campos, sigue
 * exactamente igual.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, deviceId: int, deviceUuid: string, metrics: RecordingKioskMetrics}
 */
function quioscoQueDeclaraSuCola(): array
{
    $escenario = AttendanceFixtures::scenario();
    $metrics = new RecordingKioskMetrics;

    app()->instance(KioskMetrics::class, $metrics);

    return [
        'token' => $escenario['token'],
        'deviceId' => $escenario['device'],
        'deviceUuid' => $escenario['deviceUuid'],
        'metrics' => $metrics,
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function latidoDeCola(string $token, array $body): TestResponse
{
    return Api::as($token)->post('/api/v1/kiosk/heartbeat', ['app_version' => '2.2.0', ...$body]);
}

/**
 * @return array<string, mixed>
 */
function quioscoEnElPanel(string $deviceUuid): array
{
    $respuesta = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/devices')
        ->assertOk()
        ->assertValidResponse();

    /** @var list<array<string, mixed>> $quioscos */
    $quioscos = (array) $respuesta->json('devices');

    foreach ($quioscos as $quiosco) {
        if (($quiosco['uuid'] ?? null) === $deviceUuid) {
            return $quiosco;
        }
    }

    throw new RuntimeException('El quiosco no aparece en el panel.');
}

/**
 * @param  array<string, mixed>  $quiosco
 * @return array<string, mixed>
 */
function saludEnElPanel(array $quiosco): array
{
    $salud = $quiosco['health'] ?? null;

    if (! is_array($salud)) {
        return [];
    }

    /** @var array<string, mixed> $salud */
    return $salud;
}

it('trata igual que antes el latido de una PWA anterior que no declara su almacenamiento', function (): void {
    $quiosco = quioscoQueDeclaraSuCola();

    latidoDeCola($quiosco['token'], ['pending_queue_size' => 3])
        ->assertOk()->assertValidRequest()->assertValidResponse();

    /** @var object{pending_queue_size: int|null, queue_storage: string, unreported_discards: int} $fila */
    $fila = DB::table('devices')->where('id', $quiosco['deviceId'])->first();

    expect($fila->pending_queue_size)->toBe(3)
        ->and($fila->queue_storage)->toBe('durable')
        ->and($fila->unreported_discards)->toBe(0)
        ->and($quiosco['metrics']->heartbeats[0]['queue'])->toBe(3)
        ->and($quiosco['metrics']->heartbeats[0]['degraded'])->toBeFalse()
        ->and($quiosco['metrics']->heartbeats[0]['unreported'])->toBe(0);

    $enElPanel = quioscoEnElPanel($quiosco['deviceUuid']);

    expect($enElPanel['queue_storage'])->toBe('durable')
        ->and($enElPanel['unreported_discards'])->toBe(0)
        ->and(saludEnElPanel($enElPanel)['reason'])->toBe('queue_pending');
})->group('RF-PA-07', 'RF-KI-04');

it('rechaza un tamano desconocido con la cola en IndexedDB', function (array $body): void {
    // ADR-047 y F13 del dictamen: con la cola en disco la tablet siempre sabe
    // cuantos tiene. El contrato lo fija en `400`.
    $quiosco = quioscoQueDeclaraSuCola();

    latidoDeCola($quiosco['token'], $body)->assertStatus(400);

    expect($quiosco['metrics']->heartbeats)->toBe([]);
})->with([
    'sin queue_storage' => [['pending_queue_size' => null]],
    'con durable explicito' => [['pending_queue_size' => null, 'queue_storage' => 'durable']],
    'sin el campo' => [['queue_storage' => 'memory']],
    'con un almacenamiento inventado' => [['pending_queue_size' => 0, 'queue_storage' => 'disk']],
    'con descartes negativos' => [['pending_queue_size' => 0, 'unreported_discards' => -1]],
])->group('RF-PA-07', 'RF-KI-04');

it('guarda la cola en memoria como desconocida y el panel la marca como fallo', function (): void {
    $quiosco = quioscoQueDeclaraSuCola();

    latidoDeCola($quiosco['token'], [
        'pending_queue_size' => null,
        'queue_storage' => 'memory',
        'unreported_discards' => 2,
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    /** @var object{pending_queue_size: int|null, queue_storage: string, unreported_discards: int} $fila */
    $fila = DB::table('devices')->where('id', $quiosco['deviceId'])->first();

    expect($fila->pending_queue_size)->toBeNull()
        ->and($fila->queue_storage)->toBe('memory')
        ->and($fila->unreported_discards)->toBe(2)
        // La metrica se retira (`null`), y el gauge de almacenamiento sube.
        ->and($quiosco['metrics']->heartbeats[0]['queue'])->toBeNull()
        ->and($quiosco['metrics']->heartbeats[0]['degraded'])->toBeTrue()
        ->and($quiosco['metrics']->heartbeats[0]['unreported'])->toBe(2);

    $enElPanel = quioscoEnElPanel($quiosco['deviceUuid']);

    expect($enElPanel['pending_queue_size'])->toBeNull()
        ->and($enElPanel['queue_storage'])->toBe('memory')
        ->and(saludEnElPanel($enElPanel)['verdict'])->toBe('failure')
        ->and(saludEnElPanel($enElPanel)['reason'])->toBe('queue_storage_degraded');
})->group('RF-PA-07', 'RF-KI-04');

it('avisa en el panel de los descartes que la tablet no ha conseguido avisar', function (): void {
    $quiosco = quioscoQueDeclaraSuCola();

    latidoDeCola($quiosco['token'], [
        'pending_queue_size' => 0,
        'queue_storage' => 'durable',
        'unreported_discards' => 4,
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    $enElPanel = quioscoEnElPanel($quiosco['deviceUuid']);

    expect($enElPanel['unreported_discards'])->toBe(4)
        ->and(saludEnElPanel($enElPanel)['verdict'])->toBe('warning')
        ->and(saludEnElPanel($enElPanel)['reason'])->toBe('discards_unreported');
})->group('RF-PA-07', 'RN-22');

it('vuelve a durable y a cero cuando la tablet recupera IndexedDB', function (): void {
    $quiosco = quioscoQueDeclaraSuCola();

    latidoDeCola($quiosco['token'], ['pending_queue_size' => null, 'queue_storage' => 'unavailable'])->assertOk();
    latidoDeCola($quiosco['token'], ['pending_queue_size' => 5, 'queue_storage' => 'durable'])->assertOk();

    /** @var object{pending_queue_size: int|null, queue_storage: string} $fila */
    $fila = DB::table('devices')->where('id', $quiosco['deviceId'])->first();

    expect($fila->pending_queue_size)->toBe(5)
        ->and($fila->queue_storage)->toBe('durable')
        ->and($quiosco['metrics']->heartbeats[1]['degraded'])->toBeFalse();
})->group('RF-PA-07', 'RF-KI-04');
