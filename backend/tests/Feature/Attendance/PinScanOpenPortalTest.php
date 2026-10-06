<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * Lo que el portal abierto a internet (ADR-050) NO puede cambiar en el quiosco,
 * y lo que la longitud del PIN no puede revelar en ninguna de las dos puertas.
 *
 * 1. **`/scan/pin` no conoce el bloqueo por origen** (regla dura 19, RF-AT-11):
 *    la tablet es UNA direccion por la que ficha toda la plantilla, y un `429`
 *    por origen dejaria sin fichar al turno entero por los fallos de unos pocos.
 *    Treinta fallos —mas que el umbral de veinte del portal— y el siguiente
 *    sigue siendo el rechazo generico, o el fichaje si el PIN es bueno.
 * 2. **El PIN de 8 cifras ficha por `/scan/pin`** igual que el de 6.
 * 3. **Siete cifras es un PIN equivocado, no un dato** (RS-03): el PIN emitido es
 *    de 6 o de 8 y el de 7 no existe, pero responder distinto diria cual es la
 *    longitud del de la persona. Misma respuesta y tiempo acotado, en el quiosco
 *    y en el portal.
 *
 * La zona `scan-pin` del limitador se levanta: su `429` es por dispositivo y
 * minuto, es otro control con su propia prueba, y aqui taparia lo que se mide.
 */

uses(RefreshDatabase::class);

const PIN_SCAN_OPEN_PORTAL_IP = '203.0.113.50';

const PIN_SCAN_OPEN_PORTAL_SIX = '481902';

const PIN_SCAN_OPEN_PORTAL_EIGHT = '48190275';

beforeEach(function (): void {
    config()->set('kiosk.rate_limits.pin_scan_per_device', 10_000);
    config()->set('kiosk.rate_limits.pin_scan_per_ip', 10_000);
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.portal.origin_lockout.max_failures', 20);
    config()->set('identity.portal.origin_lockout.window_seconds', 900);
    config()->set('identity.portal.origin_lockout.lockout_seconds', 3600);
    // Las medidas de tiempo repiten fallos contra la misma persona: sin esto se
    // bloquearia a la tercera y se mediria otra rama.
    config()->set('identity.pin.max_attempts', 100);
    config()->set('identity.pin.lockout_tier2_attempts', 200);
    config()->set('identity.pin.lockout_tier3_attempts', 300);

    app(Cache::class)->clear();
    Spectator::using('openapi.yaml');
});

/**
 * Quiosco emparejado y una persona con el PIN dado, con el reloj detenido.
 *
 * @return array{employee: string, code: string, publicKey: string, token: string}
 */
function pinScanOpenPortalScenario(string $pin): array
{
    $scenario = AttendanceFixtures::scenario();

    EmployeePins::issue($scenario['employee'], $pin);

    FrozenTime::at('2026-10-06 07:02:31');
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    return [
        'employee' => $scenario['employee'],
        'token' => $scenario['token'],
        'code' => EmployeePins::codeOf($scenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
    ];
}

/**
 * Un fichaje por PIN desde la direccion de la tablet.
 *
 * @param  array{code: string, publicKey: string, token: string, ...}  $scenario
 * @return TestResponse<Response>
 */
function pinScanOpenPortalPost(array $scenario, string $pin, ?string $code = null): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($scenario['token'])
        ->fromIp(PIN_SCAN_OPEN_PORTAL_IP)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-10-06T07:02:31Z',
            'employee_code' => $code ?? $scenario['code'],
            'pin_sealed' => EmployeePins::seal($pin, $scenario['publicKey']),
        ]);
}

/**
 * Treinta fallos desde la tablet, cada uno con un codigo que no existe para que
 * ninguna persona se bloquee: lo unico que acumulan es la direccion.
 *
 * @param  array{code: string, publicKey: string, token: string, ...}  $scenario
 * @return list<int>
 */
function pinScanOpenPortalThirtyFailures(array $scenario): array
{
    return array_map(
        static fn (int $i): int => pinScanOpenPortalPost($scenario, PIN_SCAN_OPEN_PORTAL_SIX, 'NOEXISTE'.$i)->getStatusCode(),
        range(1, 30),
    );
}

/**
 * Cabeceras sin las que cambian en cada peticion por diseño.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string|null>>
 */
function pinScanOpenPortalStableHeaders(TestResponse $response): array
{
    $headers = $response->headers->all();

    unset($headers['date'], $headers['x-request-id'], $headers['traceparent'], $headers['x-trace-id'], $headers['x-ratelimit-remaining']);
    ksort($headers);

    return $headers;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<array-key, mixed>
 */
function pinScanOpenPortalBody(TestResponse $response): array
{
    return array_diff_key((array) $response->json(), ['scan_id' => true]);
}

/**
 * Cuantas comparaciones de hash hace una llamada.
 *
 * **El trabajo y no el reloj.** La suite corre con `BCRYPT_ROUNDS=4`: el hash
 * cuesta menos de un milisegundo y un camino que se lo saltara tardaria lo
 * mismo que uno que no. Y `abs(a - b) < max(a, b)` —el criterio de las medidas
 * de `PinScanTest`— se cumple siempre que las dos duren algo, asi que no puede
 * fallar. Lo que delataria la longitud en produccion, con el coste real, es
 * que un PIN «imposible» se ahorrase la comparacion: eso es lo que se cuenta.
 *
 * @param  callable(): mixed  $work
 */
function pinScanOpenPortalHashChecks(callable $work): int
{
    $real = app('hash');
    $checks = 0;

    Hash::partialMock()
        ->shouldReceive('check')
        ->andReturnUsing(static function (string $value, string $hashedValue, array $options = []) use ($real, &$checks): bool {
            $checks++;

            return $real->check($value, $hashedValue, $options);
        });

    $work();

    Hash::swap($real);

    return $checks;
}

// --- 1 · El quiosco no conoce el bloqueo por origen -------------------------

it('sigue dando el rechazo generico del quiosco tras treinta fallos desde la misma direccion', function (): void {
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_SIX);

    $codigos = pinScanOpenPortalThirtyFailures($scenario);

    $trigesimoPrimero = pinScanOpenPortalPost($scenario, PIN_SCAN_OPEN_PORTAL_SIX, 'NOEXISTE31');

    expect($codigos)->toBe(array_fill(0, 30, 422));

    $trigesimoPrimero->assertStatus(422)->assertValidResponse();
})->group('RF-AT-11', 'RS-03', 'RS-12');

it('deja fichar con el PIN bueno tras treinta fallos desde la misma direccion', function (): void {
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_SIX);

    pinScanOpenPortalThirtyFailures($scenario);

    pinScanOpenPortalPost($scenario, PIN_SCAN_OPEN_PORTAL_SIX)->assertOk()->assertValidResponse();
})->group('RF-AT-11', 'RS-12');

it('no suma los fallos del quiosco a la cuenta del portal de esa direccion', function (): void {
    // Las dos puertas cuentan por separado: si la tablet sumara, sus fallos
    // cerrarian el portal a la red del hotel (y al reves seria peor).
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_SIX);

    pinScanOpenPortalThirtyFailures($scenario);

    expect(app(PortalOriginAttempts::class)->historyFor(RequestOrigin::of(PIN_SCAN_OPEN_PORTAL_IP))->failures)
        ->toBe([]);

    Api::guest()->fromIp(PIN_SCAN_OPEN_PORTAL_IP)->post('/api/v1/me/login', [
        'employee_code' => $scenario['code'],
        'pin' => PIN_SCAN_OPEN_PORTAL_SIX,
    ])->assertValidResponse(200);
})->group('RF-AT-11', 'RS-12', 'RF-ID-08');

// --- 2 · El PIN de ocho cifras ficha ----------------------------------------

it('ficha con un PIN de ocho cifras', function (): void {
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_EIGHT);

    pinScanOpenPortalPost($scenario, PIN_SCAN_OPEN_PORTAL_EIGHT)
        ->assertOk()
        ->assertValidRequest()
        ->assertValidResponse();
})->group('RF-AT-11', 'RF-ID-09');

it('no acepta los seis primeros de un PIN de ocho cifras', function (): void {
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_EIGHT);

    pinScanOpenPortalPost($scenario, '481902')->assertStatus(422)->assertValidResponse();
})->group('RF-AT-11', 'RF-ID-09', 'RS-03');

// --- 3 · Siete cifras no revela la longitud ---------------------------------

it('rechaza siete cifras en el quiosco igual que un PIN de seis equivocado', function (string $pinDeLaPersona): void {
    $scenario = pinScanOpenPortalScenario($pinDeLaPersona);

    $seis = pinScanOpenPortalPost($scenario, '000999');
    $siete = pinScanOpenPortalPost($scenario, '0009991');

    $siete->assertStatus(422)->assertValidRequest()->assertValidResponse();

    expect($seis->getStatusCode())->toBe(422)
        ->and(pinScanOpenPortalBody($siete))->toBe(pinScanOpenPortalBody($seis))
        ->and(pinScanOpenPortalStableHeaders($siete))->toBe(pinScanOpenPortalStableHeaders($seis));
})->with([
    'persona con PIN de seis' => [PIN_SCAN_OPEN_PORTAL_SIX],
    'persona con PIN de ocho' => [PIN_SCAN_OPEN_PORTAL_EIGHT],
])->group('RS-03', 'RF-AT-11', 'RF-ID-09');

it('compara el hash igual en el quiosco con siete cifras que con seis equivocadas', function (): void {
    // RS-03: un PIN de siete cifras «no puede existir», y ahorrarse la
    // comparacion lo haria mas rapido; con el coste real del hash, eso diria la
    // longitud del PIN de la persona.
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_SIX);

    $seis = pinScanOpenPortalHashChecks(fn () => pinScanOpenPortalPost($scenario, '000998'));
    $siete = pinScanOpenPortalHashChecks(fn () => pinScanOpenPortalPost($scenario, '0009991'));

    expect($seis)->toBe(1)
        ->and($siete)->toBe(1);
})->group('RS-03', 'RF-AT-11');

it('rechaza siete cifras en el portal con las mismas cabeceras que seis equivocadas', function (): void {
    // El cuerpo igual ya lo prueba `PortalOriginLockoutTest`; aqui, que tampoco
    // las cabeceras lo distinguen.
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_EIGHT);

    $seis = Api::guest()->post('/api/v1/me/login', ['employee_code' => $scenario['code'], 'pin' => '000999']);
    $siete = Api::guest()->post('/api/v1/me/login', ['employee_code' => $scenario['code'], 'pin' => '0009991']);

    $siete->assertValidResponse(401);

    expect($seis->getStatusCode())->toBe(401)
        ->and($siete->json())->toBe($seis->json())
        ->and(pinScanOpenPortalStableHeaders($siete))->toBe(pinScanOpenPortalStableHeaders($seis));
})->group('RS-03', 'RF-ID-06', 'RF-ID-09');

it('compara el hash igual en el portal con siete cifras que con seis equivocadas', function (): void {
    $scenario = pinScanOpenPortalScenario(PIN_SCAN_OPEN_PORTAL_EIGHT);

    $seis = pinScanOpenPortalHashChecks(fn () => Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $scenario['code'],
        'pin' => '000998',
    ]));
    $siete = pinScanOpenPortalHashChecks(fn () => Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $scenario['code'],
        'pin' => '0009991',
    ]));

    expect($seis)->toBe(1)
        ->and($siete)->toBe(1);
})->group('RS-03', 'RF-ID-06');
