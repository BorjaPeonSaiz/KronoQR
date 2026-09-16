<?php

declare(strict_types=1);

use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Kiosk\RecordingKioskMetrics;

/*
 * La telemetria de salud que sube el latido y la huella del codigo de servicio
 * que baja con el (**RF-PA-07**, **RF-KI-08**, tarea 3.3).
 *
 * ## Que se afirma aqui, y por que no bastaba con la unitaria
 *
 * La regla de salud ya tiene su unitaria (`CheckKioskHealthTest`). Lo que se
 * comprueba en esta suite es LA COSTURA COMPLETA, que es donde estaba el agujero
 * que describe la ficha: el latido validaba `oldest_pending_at` y despues lo
 * tiraba, y la bateria no existia en ninguna capa aunque el doc 05 §5.3 la
 * promete. Aqui se sigue el dato desde el cuerpo de la peticion hasta la
 * columna, hasta la metrica y hasta `GET /api/v1/devices`.
 *
 * Y la huella: que sea EXACTAMENTE `sha256("{uuid}:{codigo}")`, que sea `null`
 * sin codigo configurado, y que el codigo en claro no aparezca en la respuesta
 * (decision 6).
 *
 * ## Contra el contrato, siempre
 *
 * `assertValidRequest()` y `assertValidResponse()` en cada caso: de
 * `openapi.yaml` se genera el cliente TypeScript de la PWA, y un campo que el
 * servidor acepte o devuelva y el contrato no describa rompe los tres frontends
 * sin que nadie se entere aqui (RQ-06, ADR-013).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un quiosco vinculado con el doble de metricas ya enlazado.
 *
 * @return array{token: string, deviceUuid: string, deviceId: int, metrics: RecordingKioskMetrics}
 */
function quioscoConMetricas(): array
{
    $escenario = AttendanceFixtures::scenario();
    $metrics = new RecordingKioskMetrics;

    app()->instance(KioskMetrics::class, $metrics);

    return [
        'token' => $escenario['token'],
        'deviceUuid' => $escenario['deviceUuid'],
        'deviceId' => $escenario['device'],
        'metrics' => $metrics,
    ];
}

/** Deja escrito el codigo de servicio de la instalacion, por la via real: el panel. */
function configuraCodigoDeServicio(string $code): void
{
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => [SettingKey::KIOSK_SERVICE_CODE->value => $code]])
        ->assertStatus(200);
}

// --- La telemetria sube y se queda ------------------------------------------

it('persiste la bateria y la cola mas antigua que declara el latido', function (): void {
    // El agujero que cierra la tarea: `oldest_pending_at` se validaba y se
    // tiraba. Es lo que convierte «37 pendientes» en «el mas antiguo es de hace
    // tres horas», que es la diferencia entre una sincronizacion en curso y un
    // quiosco que lleva media jornada incomunicado.
    $quiosco = quioscoConMetricas();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 37,
        'oldest_pending_at' => '2026-09-16T05:12:44Z',
        'battery_level' => 83,
        'battery_charging' => true,
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    /** @var object{battery_level: int|null, battery_charging: bool|null, oldest_pending_at: string|null, pending_queue_size: int} $row */
    $row = DB::table('devices')->where('id', $quiosco['deviceId'])->first();

    expect($row->battery_level)->toBe(83)
        ->and($row->battery_charging)->toBeTrue()
        ->and($row->pending_queue_size)->toBe(37)
        ->and($row->oldest_pending_at)->toBeString()
        ->and((string) $row->oldest_pending_at)->toStartWith('2026-09-16 05:12:44');
})->group('RF-PA-07');

it('deja la telemetria a null cuando la tablet no la informa, y no la inventa', function (): void {
    // La Battery Status API solo la ofrece Chrome en Android. Guardar un cero
    // aqui pondria en aviso a la flota entera de un hotel con tablets de otra
    // marca, y ese aviso se aprenderia a ignorar en una semana.
    $quiosco = quioscoConMetricas();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'battery_level' => null,
        'battery_charging' => null,
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    /** @var object{battery_level: int|null, battery_charging: bool|null, oldest_pending_at: string|null} $row */
    $row = DB::table('devices')->where('id', $quiosco['deviceId'])->first();

    expect($row->battery_level)->toBeNull()
        ->and($row->battery_charging)->toBeNull()
        ->and($row->oldest_pending_at)->toBeNull();

    // Y la metrica tampoco se publica: ver `RedisKioskMetrics::heartbeat()`.
    expect($quiosco['metrics']->heartbeats[0]['battery'])->toBeNull();
})->group('RF-PA-07');

it('borra la cola mas antigua y la bateria cuando el latido siguiente ya no las trae', function (): void {
    // El caso que un `UPDATE` parcial se habria comido: una cola ya drenada
    // seguiria diciendo «el mas antiguo es de hace tres horas» para siempre.
    $quiosco = quioscoConMetricas();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 12,
        'oldest_pending_at' => '2026-09-16T05:12:44Z',
        'battery_level' => 40,
        'battery_charging' => false,
    ])->assertOk();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    /** @var object{battery_level: int|null, battery_charging: bool|null, oldest_pending_at: string|null, pending_queue_size: int} $row */
    $row = DB::table('devices')->where('id', $quiosco['deviceId'])->first();

    expect($row->oldest_pending_at)->toBeNull()
        ->and($row->battery_level)->toBeNull()
        ->and($row->battery_charging)->toBeNull()
        ->and($row->pending_queue_size)->toBe(0);
})->group('RF-PA-07');

it('publica la bateria como metrica solo cuando el dispositivo la declara', function (): void {
    // `kiosk_battery_level{device}` del doc 02 §8.2. Lo que se afirma es la
    // INSTRUMENTACION —que el caso de uso mide— y no que Redis guarde bien un
    // `HSET`, que es cosa de Redis.
    $quiosco = quioscoConMetricas();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 4,
        'battery_level' => 9,
        'battery_charging' => false,
    ])->assertOk();

    expect($quiosco['metrics']->heartbeats)->toHaveCount(1)
        ->and($quiosco['metrics']->heartbeats[0]['device'])->toBe($quiosco['deviceUuid'])
        ->and($quiosco['metrics']->heartbeats[0]['queue'])->toBe(4)
        ->and($quiosco['metrics']->heartbeats[0]['battery'])->toBe(9);
})->group('RF-PA-07');

it('rechaza con 400 una bateria fuera de rango sin tocar la fila', function (int|string $level): void {
    // `400` y no `422`, como todo el camino del quiosco: el `422` significa
    // «tarjeta rechazada» para este cliente y no puede compartirse con un error
    // de forma (regla dura 17).
    $quiosco = quioscoConMetricas();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'battery_level' => $level,
    ])->assertStatus(400);

    expect(DB::table('devices')->where('id', $quiosco['deviceId'])->value('battery_level'))->toBeNull();
})->with([
    'por encima de cien' => [101],
    'negativa' => [-1],
    'con texto' => ['llena'],
])->group('RF-PA-07');

// --- La huella del codigo de servicio (RF-KI-08) -----------------------------

it('devuelve null como huella mientras la instalacion no tenga codigo', function (): void {
    // Decision 7: sin huella conocida la pantalla de diagnostico se abre sin
    // codigo. Una tablet sin nada que proteger es justo la que hay que poder
    // diagnosticar (regla dura 19).
    $quiosco = quioscoConMetricas();

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])
        ->assertOk()
        ->assertValidRequest()
        ->assertValidResponse()
        ->assertJsonPath('service_code_hash', null);
})->group('RF-KI-08');

it('devuelve la huella del codigo, distinta en cada quiosco y sin el codigo dentro', function (): void {
    // `sha256("{uuid}:{codigo}")`. El UUID va dentro y no es decoracion: sin el,
    // todas las tablets guardarian la misma huella y una tabla precalculada de
    // codigos de ocho cifras valdria para todas a la vez.
    $quiosco = quioscoConMetricas();
    configuraCodigoDeServicio('48392017');

    $respuesta = Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])->assertOk()->assertValidRequest()->assertValidResponse();

    $esperada = hash('sha256', $quiosco['deviceUuid'].':48392017');

    $respuesta->assertJsonPath('service_code_hash', $esperada);

    // Y el codigo NO viaja: ni en claro ni por descuido en otro campo.
    expect($respuesta->getContent())->toBeString()
        ->and((string) $respuesta->getContent())->not->toContain('48392017')
        // La huella de otro quiosco con el mismo codigo es otra.
        ->and(hash('sha256', '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81:48392017'))->not->toBe($esperada);
})->group('RF-KI-08');

it('lleva el codigo nuevo a la tablet en el latido siguiente', function (): void {
    // Es el motivo de elegir el latido y no el padron: un codigo cambiado en el
    // panel llega a todas las tablets en sesenta segundos, sin reinstalar nada.
    $quiosco = quioscoConMetricas();
    configuraCodigoDeServicio('48392017');

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])->assertJsonPath('service_code_hash', hash('sha256', $quiosco['deviceUuid'].':48392017'));

    configuraCodigoDeServicio('900112233');

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])
        ->assertValidResponse()
        ->assertJsonPath('service_code_hash', hash('sha256', $quiosco['deviceUuid'].':900112233'));

    // Y retirarlo vuelve a dejar la pantalla sin codigo.
    configuraCodigoDeServicio('');

    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ])->assertJsonPath('service_code_hash', null);
})->group('RF-KI-08', 'RF-PD-01');

it('rechaza un codigo de servicio que no sean de 8 a 12 cifras', function (string $code): void {
    // La tablet solo tiene el teclado numerico de `PinNumericKeypad`: un codigo
    // con letras seria un codigo que nadie puede teclear donde hay que teclearlo.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => [SettingKey::KIOSK_SERVICE_CODE->value => $code]])
        ->assertStatus(422);
})->with([
    'corto' => ['1234'],
    'largo' => ['1234567890123'],
    'con letras' => ['4839a017'],
])->group('RF-KI-08', 'RF-PD-01');
