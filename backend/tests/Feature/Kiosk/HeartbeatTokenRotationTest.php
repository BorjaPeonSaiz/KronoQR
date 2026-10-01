<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Kiosk\RecordingKioskMetrics;
use Tests\Support\Time\FrozenTime;

/*
 * El relevo del token del quiosco en el latido (RF-ID-04, doc 02 §7.3, ADR-044,
 * hallazgo F1-1).
 *
 * Antes de esto, `RotateDeviceTokenIfDue` no lo llamaba nadie y la respuesta del
 * latido no tenia campo para entregar un token: a los 90 dias de emparejar, cada
 * tablet recibia `401` y los fichajes se quedaban en su cola local. Aqui se
 * comprueba la costura completa —latido, rotacion, respuesta contra el contrato
 * y el token nuevo funcionando— y la prueba de los 90 dias simulados.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un quiosco emparejado de verdad —con `IssueDeviceToken`, que pone caducidad—
 * y el doble de metricas enlazado.
 *
 * @return array{deviceId: int, deviceUuid: string, token: string, metrics: RecordingKioskMetrics, employee: string}
 */
function quioscoConTokenReal(): array
{
    $escenario = AttendanceFixtures::scenario();
    $metrics = new RecordingKioskMetrics;
    app()->instance(KioskMetrics::class, $metrics);

    $token = app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($escenario['deviceUuid']));

    if (! $token instanceof IssuedAccessToken) {
        throw new RuntimeException('No se ha emitido token para el quiosco.');
    }

    return [
        'deviceId' => $escenario['device'],
        'deviceUuid' => $escenario['deviceUuid'],
        'token' => $token->plainTextToken,
        'metrics' => $metrics,
        'employee' => $escenario['employee'],
    ];
}

/**
 * @return TestResponse<Response>
 */
function latidoCon(string $token): TestResponse
{
    return Api::as($token)->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
    ]);
}

/** Deja el token vigente del quiosco en el dia 80 de 90. */
function tokenDeQuioscoEnElDia80(int $deviceId): void
{
    DB::table('personal_access_tokens')->where('tokenable_id', $deviceId)->update([
        'created_at' => now()->subDays(80),
        'expires_at' => now()->addDays(10),
    ]);
}

/**
 * @param  TestResponse<Response>  $respuesta
 */
function relevoDe(TestResponse $respuesta): string
{
    $value = $respuesta->json('rotated_token.value');

    if (! is_string($value) || $value === '') {
        throw new RuntimeException('El latido no ha traido relevo del token.');
    }

    return $value;
}

it('no trae relevo mientras el token no ha pasado el 80 % de su vida', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $quiosco = quioscoConTokenReal();

    $respuesta = latidoCon($quiosco['token'])->assertOk()->assertValidRequest()->assertValidResponse();

    // La clave se OMITE, no viaja a `null`: el contrato la declara opcional.
    expect(array_key_exists('rotated_token', (array) $respuesta->json()))->toBeFalse()
        ->and($quiosco['metrics']->tokenRotations)->toBe([]);
})->group('RF-ID-04', 'RQ-06');

it('trae el relevo pasado el 80 %, conforme al contrato, y el relevo funciona', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $quiosco = quioscoConTokenReal();
    tokenDeQuioscoEnElDia80($quiosco['deviceId']);

    $respuesta = latidoCon($quiosco['token'])->assertOk()->assertValidRequest()->assertValidResponse();
    $relevo = relevoDe($respuesta);

    expect($relevo)->not->toBe($quiosco['token'])
        ->and($respuesta->json('rotated_token.expires_at'))->toBe('2026-08-30T08:00:00Z')
        ->and($quiosco['metrics']->tokenRotations)->toBe(['issued']);

    // El relevo firma el latido siguiente, que ya no trae otro relevo...
    $siguiente = latidoCon($relevo)->assertOk()->assertValidResponse();

    expect(array_key_exists('rotated_token', (array) $siguiente->json()))->toBeFalse();

    // ...y su primer uso retiro el token viejo.
    latidoCon($quiosco['token'])->assertUnauthorized();
    Api::as($relevo)->get('/api/v1/kiosk/roster')->assertOk();
})->group('RF-ID-04', 'RS-04', 'RQ-06');

it('si la respuesta con el relevo se pierde, el token viejo sigue fichando y el siguiente latido trae otro', function (): void {
    FrozenTime::at('2026-06-01 08:00:00');
    $quiosco = quioscoConTokenReal();
    tokenDeQuioscoEnElDia80($quiosco['deviceId']);

    $perdido = relevoDe(latidoCon($quiosco['token'])->assertOk());

    FrozenTime::at('2026-06-01 08:01:00');
    $reentregado = relevoDe(latidoCon($quiosco['token'])->assertOk()->assertValidResponse());

    expect($reentregado)->not->toBe($perdido)
        ->and($quiosco['metrics']->tokenRotations)->toBe(['issued', 'issued']);

    // El relevo que no llego se retiro sin haberse usado; el reentregado vale.
    Api::as($perdido)->get('/api/v1/kiosk/roster')->assertUnauthorized();
    Api::as($reentregado)->get('/api/v1/kiosk/roster')->assertOk();
})->group('RF-ID-04', 'RS-04');

it('si la rotacion falla, el latido responde igual sin relevo y el token sigue valiendo', function (): void {
    // Regla dura 19: una rotacion rota no puede tumbar la unica señal de que la
    // tablet sigue viva. Se reintenta en el latido siguiente.
    FrozenTime::at('2026-06-01 08:00:00');
    $quiosco = quioscoConTokenReal();
    tokenDeQuioscoEnElDia80($quiosco['deviceId']);

    $real = app(DeviceTokenIssuer::class);
    app()->instance(DeviceTokenIssuer::class, new class($real) implements DeviceTokenIssuer
    {
        public function __construct(private DeviceTokenIssuer $real) {}

        public function issueFor(Device $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken
        {
            return $this->real->issueFor($device, $issuedAt, $expiresAt);
        }

        public function issueAlongside(Device $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken
        {
            throw new RuntimeException('Fallo simulado al emitir el relevo.');
        }

        public function revokeAllFor(Device $device): void
        {
            $this->real->revokeAllFor($device);
        }

        public function lockedTokensOf(Device $device): array
        {
            return $this->real->lockedTokensOf($device);
        }

        public function shortenExpiry(Device $device, int $tokenId, DateTimeImmutable $until): void
        {
            $this->real->shortenExpiry($device, $tokenId, $until);
        }

        public function retire(Device $device, array $tokenIds): void
        {
            $this->real->retire($device, $tokenIds);
        }
    });

    $respuesta = latidoCon($quiosco['token'])->assertOk()->assertValidResponse();

    expect(array_key_exists('rotated_token', (array) $respuesta->json()))->toBeFalse()
        ->and($quiosco['metrics']->tokenRotations)->toBe(['failed'])
        // La transaccion se deshizo entera: el token viejo no entro en solape.
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $quiosco['deviceId'])->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $quiosco['deviceId'])->value('expires_at'))
        ->toStartWith('2026-06-11 08:00:00')
        ->and(DB::table('audit_log')->where('action', 'device.paired')->count())->toBe(1);

    Api::as($quiosco['token'])->get('/api/v1/kiosk/roster')->assertOk();
})->group('RF-ID-04', 'RF-KI-04');

it('a los 90 dias simulados el quiosco sigue autenticando y sincronizando gracias a la rotacion', function (): void {
    // F1-1, la prueba que faltaba. Emparejado el 1 de febrero, con un latido
    // cada pocos dias: sin rotacion, el 2 de mayo la tablet recibiria 401 y los
    // fichajes se quedarian en su cola.
    FrozenTime::at('2026-02-01 07:00:00');
    $quiosco = quioscoConTokenReal();
    $token = $quiosco['token'];
    $relevos = [];

    foreach (range(1, 200) as $dia) {
        FrozenTime::at((new DateTimeImmutable('2026-02-01 07:00:00'))->modify('+'.$dia.' days')->format('Y-m-d H:i:s'));

        $respuesta = latidoCon($token)->assertOk();
        $relevo = $respuesta->json('rotated_token.value');

        if (is_string($relevo)) {
            $respuesta->assertValidResponse();
            $relevos[] = $dia;
            $token = $relevo;
        }
    }

    // Dia 200: el token original caduco hace mucho y la tablet sigue dentro.
    expect($relevos)->toBe([72, 144])
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $quiosco['deviceId'])->count())->toBe(1);

    Api::as($quiosco['token'])->get('/api/v1/kiosk/roster')->assertUnauthorized();
    Api::as($token)->get('/api/v1/kiosk/roster')->assertOk();

    // Y el fichaje de ese dia llega al registro legal.
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa', $quiosco['employee']),
    );

    Api::as($token)
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', ['scans' => [[
            'scan_id' => Str::uuid7()->toString(),
            'occurred_at' => '2026-08-20T06:58:00Z',
            'qr_payload' => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
        ]]])
        ->assertStatus(207);

    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-ID-04', 'RF-KI-04');
