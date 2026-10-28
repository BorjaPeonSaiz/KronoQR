<?php

declare(strict_types=1);

use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Kiosk\RecordingKioskMetrics;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La recogida repetida del emparejamiento** (V6-KI-02, 2.3.0), validada
 * contra el contrato.
 *
 * Si la respuesta `paired` se pierde, la tablet se quedaba con un quiosco dado
 * de alta y sin token. Ahora, entre los 10 y los 60 segundos desde la primera
 * recogida, desde el mismo origen y con el mismo secreto, repetir el `claim`
 * reemite el token del MISMO dispositivo y retira el anterior. Fuera de eso, el
 * rechazo generico de siempre (regla dura 17).
 */

uses(RefreshDatabase::class);

const RECOGIDA_INICIO = '2026-09-07 10:00:00';

const RECOGIDA_IP = '192.0.2.10';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at(RECOGIDA_INICIO);
});

/**
 * Pide un codigo, lo confirma un `admin` y recoge el token por primera vez.
 *
 * @return array{pairing_id: string, pairing_secret: string, token: string}
 */
function emparejadoUnaVez(): array
{
    WorkforceFixtures::site();

    /** @var array{pairing_id: string, pairing_secret: string, code: string} $ticket */
    $ticket = Api::guest()->fromIp(RECOGIDA_IP)->post('/api/v1/kiosk/pair', ['app_version' => '2.3.0'])->json();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $ticket['code'], 'name' => 'Recepcion'])
        ->assertOk();

    $primera = recogerDesde(RECOGIDA_IP, $ticket);
    $primera->assertOk();

    /** @var string $token */
    $token = $primera->json('token.value');

    return [...$ticket, 'token' => $token];
}

/**
 * @param  array{pairing_id: string, pairing_secret: string, ...}  $ticket
 * @return TestResponse<Response>
 */
function recogerDesde(string $ip, array $ticket): TestResponse
{
    return Api::guest()->fromIp($ip)->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $ticket['pairing_id'],
        'pairing_secret' => $ticket['pairing_secret'],
    ]);
}

function segundosDespues(int $segundos): void
{
    FrozenTime::at((new DateTimeImmutable(RECOGIDA_INICIO.' UTC'))->modify('+'.$segundos.' seconds')->format('Y-m-d H:i:s'));
}

/**
 * Si el token sigue sirviendo para algo del quiosco.
 */
function tokenVivo(string $token): bool
{
    Auth::forgetGuards();

    return Api::as($token)->get('/api/v1/kiosk/roster')->getStatusCode() !== 401;
}

it('reemite el token del mismo quiosco y retira el anterior si la recogida se repite a los 15 s', function (): void {
    $metricas = new RecordingKioskMetrics;
    app()->instance(KioskMetrics::class, $metricas);

    $ticket = emparejadoUnaVez();
    segundosDespues(15);

    $repetida = recogerDesde(RECOGIDA_IP, $ticket);

    $repetida->assertOk()->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('status', 'paired')
        ->assertJsonPath('device.name', 'Recepcion');

    /** @var string $nuevo */
    $nuevo = $repetida->json('token.value');

    expect($nuevo)->not->toBe($ticket['token'])
        // El MISMO dispositivo: ni un segundo quiosco ni otra fila.
        ->and(DB::table('devices')->count())->toBe(1)
        ->and($repetida->json('device.uuid'))->toBe(DB::table('devices')->value('uuid'))
        // Un solo token vivo para la tablet.
        ->and(DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%Device%')->count())->toBe(1)
        ->and(tokenVivo($nuevo))->toBeTrue()
        ->and(tokenVivo($ticket['token']))->toBeFalse()
        ->and(DB::table('device_pairing_requests')->value('reclaims'))->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'device.pairing_reclaimed')->count())->toBe(1)
        ->and($metricas->reclaimed)->toBe(1);

    /** @var object{actor_type: string, subject_type: string, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'device.pairing_reclaimed')->first();

    /** @var array<string, mixed> $carga */
    $carga = json_decode($asiento->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('system')
        ->and($asiento->subject_type)->toBe('device')
        ->and($carga)->toMatchArray([
            'pairing_request_uuid' => $ticket['pairing_id'],
            'seconds_since_first_claim' => 15,
            'reclaim' => 1,
        ])
        // Ni la direccion ni su huella (regla dura 21).
        ->and(json_encode($carga))->not->toContain(RECOGIDA_IP);
})->group('RF-PD-06', 'RS-04');

it('no guarda la direccion de origen en claro', function (): void {
    emparejadoUnaVez();

    $origen = DB::table('device_pairing_requests')->value('claimed_origin');

    expect($origen)->toBeString()
        ->and($origen)->toMatch('/^[0-9a-f]{64}$/')
        ->and($origen)->not->toContain(RECOGIDA_IP)
        ->and($origen)->not->toBe(hash('sha256', RECOGIDA_IP));
})->group('RF-PD-06', 'RS-08');

it('rechaza la recogida repetida antes de 10 s, a los 60 s o desde otra direccion', function (int $segundos, string $ip): void {
    $ticket = emparejadoUnaVez();
    segundosDespues($segundos);

    recogerDesde($ip, $ticket)
        ->assertStatus(422)
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:pairing-rejected');

    // El token de la primera recogida sigue siendo el unico y sigue vivo.
    expect(tokenVivo($ticket['token']))->toBeTrue()
        ->and(DB::table('device_pairing_requests')->value('reclaims'))->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'device.pairing_reclaimed')->count())->toBe(0);
})->with([
    'a los 9 s' => [9, RECOGIDA_IP],
    'a los 60 s justos' => [60, RECOGIDA_IP],
    'a los 15 s desde otra direccion' => [15, '198.51.100.7'],
])->group('RF-PD-06', 'RS-03');

it('rechaza la recogida repetida con el secreto equivocado como cualquier otro', function (): void {
    $ticket = emparejadoUnaVez();
    segundosDespues(15);

    recogerDesde(RECOGIDA_IP, [...$ticket, 'pairing_secret' => str_repeat('x', 43)])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:pairing-rejected');

    expect(tokenVivo($ticket['token']))->toBeTrue();
})->group('RF-PD-06', 'RS-03');

it('cuenta la ventana desde la primera recogida y no la alarga al repetir', function (): void {
    $ticket = emparejadoUnaVez();

    segundosDespues(20);
    recogerDesde(RECOGIDA_IP, $ticket)->assertOk();

    segundosDespues(45);
    $segunda = recogerDesde(RECOGIDA_IP, $ticket);
    $segunda->assertOk();

    segundosDespues(61);
    recogerDesde(RECOGIDA_IP, $ticket)->assertStatus(422);

    /** @var string $ultimo */
    $ultimo = $segunda->json('token.value');

    expect(DB::table('device_pairing_requests')->value('reclaims'))->toBe(2)
        ->and(tokenVivo($ultimo))->toBeTrue()
        ->and(DB::table('devices')->count())->toBe(1);
})->group('RF-PD-06');

it('no repite la recogida si el token de la primera ya se ha usado', function (): void {
    // Refuerzo de V6-KI-02: una tablet que perdio la respuesta no ha podido
    // usar el token. Si ya autentico peticiones, esta en manos de alguien y
    // reemitirlo se lo quitaria —o se lo daria a quien tuviera el secreto—.
    $ticket = emparejadoUnaVez();

    expect(tokenVivo($ticket['token']))->toBeTrue();
    expect(DB::table('personal_access_tokens')->where('name', 'like', 'kiosk:%')->whereNotNull('last_used_at')->count())->toBe(1);

    segundosDespues(15);

    recogerDesde(RECOGIDA_IP, $ticket)
        ->assertStatus(422)
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:pairing-rejected');

    expect(tokenVivo($ticket['token']))->toBeTrue()
        ->and(DB::table('device_pairing_requests')->value('reclaims'))->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'device.pairing_reclaimed')->count())->toBe(0);
})->group('RF-PD-06', 'RS-04');
