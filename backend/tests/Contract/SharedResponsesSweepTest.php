<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spectator\Middleware as SpectatorMiddleware;
use Spectator\RequestFactory;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Contract\Support\ContractSweep;
use Tests\Contract\Support\ExhaustedRateLimiter;
use Tests\Contract\Support\OpenRateLimiter;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Http\AuthorizationMatrix;

/*
 * **LAS RESPUESTAS COMPARTIDAS, VALIDADAS EN CADA OPERACION QUE LAS DECLARA**
 * (Q3 de la verificacion de la 2.1.0 y de la 2.2.1; RQ-06, RQ-14).
 *
 * `401`, `403`, `415`, `400` y `429` salen del mismo middleware en todas las
 * rutas, y el contrato los declara en casi cien operaciones cada uno. Cada
 * prueba recorre las operaciones que declaran su codigo, leidas del propio
 * `openapi.yaml`, lo provoca por HTTP de verdad y valida cada respuesta con
 * Spectator ({@see ContractSweep::discrepancies()}). Si una operacion declara
 * un codigo que no produce —o lo produce con otra forma—, sale su linea.
 *
 * Los actores y sus tokens son los de la matriz de autorizacion
 * ({@see AuthorizationMatrix}): el `403` se pide con el primer actor al que la
 * matriz deniega la ruta, y el `415`, el `400` y el `429` con el primero al que
 * la autoriza, porque en las rutas con sesion el cuerpo y el limitador se miran
 * despues de autenticar.
 *
 * Las operaciones que un barrido aparta estan en `ContractSweep::SET_ASIDE`,
 * cada una con su motivo, y su par lo tiene que validar una prueba explicita:
 * lo exige `Tests\Architecture\ContractStatusCoverageTest`. El `503` va aparte,
 * en `DependencyUnavailableSweepTest`, porque tira PostgreSQL y no puede
 * envolverse en `RefreshDatabase`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/** Un JSON valido con un campo que ninguna operacion admite. */
const SHARED_RESPONSES_SWEEP_UNKNOWN_FIELD = ['campo_que_no_existe' => 1];

/** Un cuerpo declarado JSON que no lo es: le falta el valor y el cierre (D6). */
const SHARED_RESPONSES_SWEEP_MALFORMED_JSON = '{"campo":';

/**
 * Los siete tokens de la matriz, con el limitador abierto: un centenar de
 * peticiones con el mismo token no pueden toparse con el cupo de una zona.
 *
 * @return array<string, string>
 */
function sharedResponsesSweepTokens(): array
{
    $tokens = AuthorizationMatrix::tokens(AuthorizationMatrix::installation());
    app()->instance(RateLimiter::class, new OpenRateLimiter(resolve(RateLimiter::class)));

    return $tokens;
}

it('pasa toda operacion del contrato por el middleware de Spectator, o sus validaciones no comprobarian nada', function (): void {
    // `assertValidResponse()` sobre una ruta que Spectator no ve pasa en verde
    // sin mirar nada. Le pasaba a `POST /broadcasting/auth`, que vive fuera del
    // grupo `api`, hasta que se le dio el suyo (`channel-auth`).
    $sinValidar = array_values(array_filter(
        array_keys(ContractSweep::responses()),
        static function (string $operation): bool {
            [$method, $uri] = ContractSweep::requestOf($operation);
            $route = Route::getRoutes()->match(Request::create($uri, $method));

            return ! in_array(SpectatorMiddleware::class, Route::gatherRouteMiddleware($route), true);
        },
    ));

    expect($sinValidar)->toBe([])
        ->and(count(ContractSweep::responses()))->toBeGreaterThan(100);
})->group('RQ-06', 'RQ-14');

it('responde 401 con la forma del contrato a la peticion sin credenciales, en cada operacion que lo declara', function (): void {
    $discrepancias = ContractSweep::discrepancies(401, static fn (string $operation) => ContractSweep::send(Api::guest(), $operation));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14', 'RQ-07');

it('responde 403 con la forma del contrato al actor que la matriz deniega, en cada operacion que lo declara', function (): void {
    $tokens = sharedResponsesSweepTokens();

    $discrepancias = ContractSweep::discrepancies(403, static fn (string $operation) => ContractSweep::send(
        ContractSweep::clientDenied($operation, $tokens),
        $operation,
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14', 'RQ-07');

it('responde 415 con la forma del contrato a un cuerpo que no es JSON, en cada operacion que lo declara', function (): void {
    $tokens = sharedResponsesSweepTokens();

    $discrepancias = ContractSweep::discrepancies(415, static fn (string $operation) => ContractSweep::sendRaw(
        ContractSweep::clientAllowed($operation, $tokens),
        $operation,
        'text/plain',
        'esto no es JSON',
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14');

it('responde 400 con la forma del contrato a un JSON mal formado, en cada operacion que lo declara', function (): void {
    // D6: el `400` que puede darse en toda operacion con cuerpo, lo valide
    // ella con `400` o con `422`. El esquema lo distingue por el `type`
    // (`BadRequestProblem`, sin `errors`), asi que un `invalid-request` aqui
    // tambien saldria como discrepancia.
    $tokens = sharedResponsesSweepTokens();

    $discrepancias = ContractSweep::discrepancies(400, static fn (string $operation) => ContractSweep::sendRaw(
        ContractSweep::clientAllowed($operation, $tokens),
        $operation,
        'application/json',
        SHARED_RESPONSES_SWEEP_MALFORMED_JSON,
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias))
        ->and(ContractSweep::sweptOperations(400))->toContain('POST /api/v1/client-errors', 'POST /api/v1/kiosk/pair/confirm');
})->group('RQ-06', 'RQ-14');

it('responde 400 con la forma del contrato a un campo que la operacion no admite, en cada operacion que valida con 400', function (): void {
    // La otra forma del `400`: `invalid-request` con `errors`, en las que
    // validan con `400` y no con `422` —el camino del quiosco y el acceso al
    // portal—. Las que lo declaran como `UnreadableBody` validan con `422` y
    // las barre el `422`.
    $tokens = sharedResponsesSweepTokens();
    $validanCon400 = [
        ...ContractSweep::declaringResponse(400, 'InvalidRequest'),
        ...ContractSweep::declaringResponse(400, 'InvalidQuery'),
    ];

    $discrepancias = ContractSweep::discrepanciesAmong($validanCon400, 400, static fn (string $operation) => ContractSweep::sendWithUnknownField(
        ContractSweep::clientAllowed($operation, $tokens),
        $operation,
        SHARED_RESPONSES_SWEEP_UNKNOWN_FIELD,
    ));

    // Las cifras del dia en que se escribio: ocho con cuerpo y el padron, y
    // las dos que validan con `422` fuera.
    expect($discrepancias)->toBe([], implode("\n", $discrepancias))
        ->and($validanCon400)->toHaveCount(9)
        ->and(ContractSweep::declaringResponse(400, 'UnreadableBody'))
        ->toBe(['POST /api/v1/client-errors', 'POST /api/v1/kiosk/pair/confirm']);
})->group('RQ-06', 'RQ-14');

it('responde 422 con la forma del contrato a un campo que la operacion no admite, en cada operacion que lo declara', function (): void {
    // `RejectsUnknownInput`: un campo de mas no se tira en silencio, porque el
    // cliente creeria que se ha aplicado. Es el `422` que toda operacion con
    // validacion puede dar, con o sin cuerpo.
    $tokens = sharedResponsesSweepTokens();

    $discrepancias = ContractSweep::discrepancies(422, static fn (string $operation) => ContractSweep::sendWithUnknownField(
        ContractSweep::clientAllowed($operation, $tokens),
        $operation,
        SHARED_RESPONSES_SWEEP_UNKNOWN_FIELD,
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14');

it('responde 404 con la forma del contrato a un recurso que no existe, en cada operacion que lo declara', function (): void {
    // Un `uuid` v7 y un identificador que la instalacion no tiene, pedidos por
    // un actor que si puede: la autorizacion pasa y lo que falta es el recurso.
    $tokens = sharedResponsesSweepTokens();

    $discrepancias = ContractSweep::discrepancies(404, static fn (string $operation) => ContractSweep::send(
        ContractSweep::clientAllowed($operation, $tokens),
        $operation,
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14');

it('responde 429 con la forma del contrato cuando la zona de limite esta agotada, en cada operacion que lo declara', function (): void {
    $tokens = AuthorizationMatrix::tokens(AuthorizationMatrix::installation());
    app()->instance(RateLimiter::class, new ExhaustedRateLimiter(resolve(RateLimiter::class)));

    $discrepancias = ContractSweep::discrepancies(429, static fn (string $operation) => ContractSweep::send(
        ContractSweep::clientAllowed($operation, $tokens),
        $operation,
    ));

    expect($discrepancias)->toBe([], implode("\n", $discrepancias));
})->group('RQ-06', 'RQ-14', 'RS-02');

it('senala la operacion que responde otro codigo del que se barre', function (): void {
    // El control del barrido: con credenciales de admin, `GET /settings` no
    // responde 401, y la linea tiene que salir. Sin esto, un acumulador que
    // nunca acumulara pasaria en verde.
    $admin = AuthorizationMatrix::tokenFor(AuthorizationMatrix::ADMIN, AuthorizationMatrix::installation());

    $linea = ContractSweep::discrepancy('GET /api/v1/settings', 401, ContractSweep::send(Api::as($admin), 'GET /api/v1/settings'));

    expect($linea)->toStartWith('GET /api/v1/settings: esperado 401, recibido 200');
})->group('RQ-06');

it('no atribuye a una operacion el incumplimiento que Spectator anoto en la peticion anterior', function (): void {
    // La respuesta fabricada no pasa por el middleware de Spectator, que es el
    // unico que reinicia lo validado: sin olvidarlo antes de cada envio, la
    // excepcion de la peticion anterior saldria como incumplimiento de esta.
    resolve(RequestFactory::class)->responseException = new RuntimeException('incumplimiento de la peticion anterior');

    $discrepancias = ContractSweep::discrepanciesAmong(
        ['GET /api/v1/health'],
        200,
        static fn (string $operation): TestResponse => TestResponse::fromBaseResponse(new SymfonyResponse('', 200)),
    );

    expect($discrepancias)->toBe([]);
})->group('RQ-06');

it('senala la respuesta que trae el codigo barrido pero no la forma del contrato', function (): void {
    // El otro control: `/health` responde 200 con un cuerpo que su esquema no
    // admite. El codigo coincide; lo que tiene que saltar es Spectator.
    Route::middleware('api')->get('api/v1/health', static fn (): array => ['estado' => 'inventado']);

    $linea = ContractSweep::discrepancy('GET /api/v1/health', 200, ContractSweep::send(Api::guest(), 'GET /api/v1/health'));

    expect($linea)->toStartWith('GET /api/v1/health: 200 que no cumple el contrato');
})->group('RQ-06');
