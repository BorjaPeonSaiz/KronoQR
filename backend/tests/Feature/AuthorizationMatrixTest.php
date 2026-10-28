<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Http\AuthorizationMatrix;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\FakeInstructionsSheetRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;

/*
 * La matriz de autorizacion de la API, cruzada con el router real (regla dura
 * 18, RQ-07, A1 de la verificacion de la 2.1.0).
 *
 * ## Por que existe, si ya hay cien pruebas de 403
 *
 * Las pruebas negativas de cada bloque (`AuthorizationNegativeTest`,
 * `SetupAuthorizationTest`, `TwoFactorAuthenticationTest`...) se escriben a mano
 * y prueban los roles en los que alguien penso. La verificacion de la 2.1.0
 * conto 19 combinaciones ruta x rol que ninguna probaba —el segundo factor con
 * rrhh, responsable, auditor y empleado; el asistente y la importacion con el
 * empleado; `/auth/me` con el quiosco y el portal— y la re-verificacion de la
 * 2.2.0 encontro las mismas 19 siete bloques despues. Lo que se hace a mano se
 * olvida.
 *
 * Esta matriz no sustituye a aquellas, que prueban el porque (el alcance por
 * departamento, la policy con el ambito abierto, el asiento en auditoria). Lo
 * que anade es que **ninguna ruta ni ningun rol se quedan fuera**:
 *
 *   1. Cada ruta autenticada del router tiene que estar declarada aqui con los
 *      actores que la alcanzan. Una ruta nueva sin declarar rompe la CI el dia
 *      en que se escribe, y declararla obliga a decidir quien la usa.
 *   2. Cada actor que NO esta en la lista de una ruta recibe `403` —o el `401`
 *      que el contrato fija para ese caso— por HTTP, de verdad.
 *   3. Cada actor que SI esta en la lista no recibe ni `401` ni `403`. Sin esta
 *      mitad, la forma barata de poner la CI en verde seria apuntar a todos los
 *      actores como autorizados.
 *   4. Las rutas publicas son una lista cerrada: una ruta nueva que se olvide de
 *      `auth:sanctum` no escapa de la matriz, rompe la prueba de publicas.
 *
 * ## Los actores, emitidos como los emite el producto (R4-QA-07)
 *
 * El empleado es una **sesion de portal** abierta por `POST /me/login`, no una
 * cuenta `users` con rol `empleado` que el producto no puede emitir; el quiosco
 * es un **token de dispositivo**, no una cuenta con rol `kiosk`. Una policy que
 * mirase el modelo del portador se comportaria distinto con cada uno, y aqui se
 * prueba el que existe en una instalacion.
 *
 * Dos portadores no estan, porque ya tienen su recorrido del mismo router: los
 * accesos de soporte del fabricante, cuyos tres alcances recorre
 * `Tests\Feature\Product\SupportScopeRoutesTest`, y la sesion de contrasena
 * temporal (`password:change`, RF-ID-10), que recorre
 * `Tests\Feature\Identity\PasswordChangeSessionRoutesTest`.
 *
 * La sesion pendiente de segundo factor es la de un **admin** a proposito: con
 * cualquier otro rol, en las rutas solo de admin el `403` lo produciria el rol y
 * no el estado pendiente, y una ruta nueva sin `ability:` dejaria pasar el token
 * pendiente de un admin sin que nada fallara.
 *
 * ## Donde vive la tabla
 *
 * La matriz, los actores y sus tokens estan en
 * {@see AuthorizationMatrix}, porque los barridos del contrato
 * (`tests/Contract/*SweepTest.php`) piden el `403` de cada operacion con un
 * actor al que la matriz deniega. Esta prueba es la que la ata al router.
 *
 * ## Una prueba por ruta, no una por celda
 *
 * Cada caso siembra la instalacion una vez, emite los siete tokens y pide la
 * ruta con cada uno y sin ninguno; acumula las discrepancias y afirma la lista
 * vacia, con la ruta, el actor y el estado en cada mensaje. Las peticiones van
 * por `Api`, que olvida los guards entre una y otra (`Auth::forgetGuards()`):
 * sin eso Sanctum reutilizaria el usuario de la primera. Primero se piden las
 * denegaciones y despues las autorizaciones, porque una autorizada puede
 * escribir (dar de baja a la persona, restablecer su PIN) y cambiar lo que
 * responderia una denegacion posterior.
 *
 * ## Lo que NO afirma
 *
 * Que el `403` deje asiento: el doc 02 §9.4 solo lo exige cuando se deniega por
 * alcance, y eso lo prueban `DepartmentScopeTest`, `IncidentScopeTest` y
 * `AbsenceScopeTest`. Ni que el responsable vea solo su departamento: aqui el
 * empleado de la ruta es de su departamento a proposito, para que lo que se
 * mida sea el rol y no el alcance.
 *
 * Lo que SI afirma de la auditoria es lo contrario: un 403 por rol o por
 * ambito no deja `access.denied` (doc 02 §9.4, doc 07 A-16); si lo dejara,
 * llenaria de ruido lo que se enseña en una inspeccion. En esta instalacion
 * solo una denegacion es por alcance, y esta escrita abajo con los asientos
 * que deja; cualquier otro `access.denied` al terminar un caso es un fallo.
 */

/**
 * Las rutas cuya denegacion en la instalacion de la matriz SI es por alcance,
 * con los `access.denied` que deja cada caso.
 */
const AUTHORIZATION_MATRIX_SCOPE_DENIALS = [
    // El responsable tiene el permiso de la presencia en vivo, pero su alcance
    // no llega al canal de toda la instalacion (`private-presence.all`):
    // `PresenceChannelAuthorizer` lo anota por el diario de autorizacion, como
    // `PresenceChannelAuditTest` comprueba con el canal de otro departamento.
    'POST api/v1/broadcasting/auth' => 1,
];

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Sin licencia, lo accesorio responde `402` (ADR-023) y el control positivo
    // no distinguiria una licencia ausente de una autorizacion rota.
    LicenseKeys::grantAll();

    // El difusor real y no el `null` de la suite: `NullBroadcaster` firma
    // cualquier canal a cualquiera y `broadcasting/auth` daria `200` al
    // auditor. Es la misma preparacion que `PresenceChannelAuthorizationTest`.
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'kronoqr-test-key');
    config()->set('broadcasting.connections.reverb.secret', 'kronoqr-test-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'kronoqr-test');

    require base_path('routes/channels.php');

    // Cada caso pide la ruta ocho veces, y dos zonas de limite cuentan menos de
    // eso por minuto con una clave que comparten los portadores: `2fa`, por IP,
    // y `diagnostics`, por IP y con una sola cuenta «desconocido» para todo lo
    // que no es de gestion. El limitador va antes de la autorizacion, asi que
    // el `429` taparia el `403` que se mide. Los limites tienen sus pruebas
    // (`TwoFactorRateLimitTest`, `DiagnosticsEndpointTest`); aqui se
    // apartan.
    config()->set('identity.two_factor.rate_limit_per_minute', 1000);
    config()->set('product.diagnostics_rate_limit_per_minute', 1000);

    // Sin Chromium: la matriz mide quien entra, no como se imprime. Con el
    // navegador real, un arranque lento o fallido del runner daba `500` a un
    // rol autorizado (la hoja de instrucciones en la CI del bloque 14).
    app()->instance(CardRenderer::class, new FakeCardRenderer);
    app()->instance(InstructionsSheetRenderer::class, new FakeInstructionsSheetRenderer);
});

/**
 * El cuerpo de las rutas que no se pueden juzgar vacias: el canal es lo que la
 * policy de `broadcasting/auth` decide.
 *
 * @return array<string, mixed>
 */
function authorizationMatrixBodyFor(string $route): array
{
    return str_contains($route, 'broadcasting/auth')
        ? ['socket_id' => '1234.5678', 'channel_name' => 'private-presence.all']
        : [];
}

/**
 * Las claves `VERBO uri` de las rutas de la API, separadas por si llevan sesion.
 *
 * @return list<string>
 */
function authorizationMatrixRouterKeys(bool $authenticated): array
{
    $keys = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $withSession = \in_array('auth:sanctum', $route->gatherMiddleware(), true);
        $keys[] = str_starts_with($route->uri(), 'api/v1') && $withSession === $authenticated
            ? authorizationMatrixKeysOf($route)
            : [];
    }

    $keys = array_values(array_unique(array_merge(...$keys)));
    sort($keys);

    return $keys;
}

/**
 * @return list<string>
 */
function authorizationMatrixKeysOf(Route $route): array
{
    return array_values(array_map(
        static fn (string $method): string => $method.' '.$route->uri(),
        array_diff($route->methods(), ['HEAD']),
    ));
}

/**
 * Cada ruta autenticada, un caso por ruta.
 *
 * @return array<string, array{0: string}>
 */
function authorizationMatrixRoutes(): array
{
    $routes = array_keys(AuthorizationMatrix::routes());

    return array_combine($routes, array_map(static fn (string $route): array => [$route], $routes));
}

/**
 * Lo que la ruta responde distinto de lo que la matriz dice, en una linea por
 * actor: vacio si todo cuadra.
 *
 * Tres comprobaciones, en este orden: sin token, `401` con el problema
 * `unauthenticated`; cada actor no autorizado, `403` (o el `401` de
 * {@see AuthorizationMatrix::unauthenticatedDenials()}); cada actor autorizado,
 * ni `401` ni `403` ni un `5xx` —un `500` no es «ha pasado la autorizacion», es
 * un fallo del producto que esta mitad tiene que ver—.
 *
 * @param  array{employee: string, responsable: string}  $installation
 * @param  array<string, string>  $tokens
 * @return list<string>
 */
function authorizationMatrixDiscrepanciesOf(string $route, array $installation, array $tokens): array
{
    $allowed = AuthorizationMatrix::routes()[$route];
    $unauthenticated = AuthorizationMatrix::unauthenticatedDenials()[$route] ?? [];
    [$method, $uri] = AuthorizationMatrix::requestOf($route, $installation);
    $body = authorizationMatrixBodyFor($route);
    $found = [];

    $guest = Api::guest()->call($method, $uri, $body);
    $found[] = $guest->getStatusCode() === 401 && $guest->json('type') === 'urn:kronoqr:problem:unauthenticated'
        ? null
        : sprintf('%s · sin token: esperado 401 unauthenticated, recibido %d', $route, $guest->getStatusCode());

    foreach (array_diff(AuthorizationMatrix::actors(), $allowed) as $actor) {
        $expected = \in_array($actor, $unauthenticated, true) ? 401 : 403;
        $status = Api::as($tokens[$actor])->call($method, $uri, $body)->getStatusCode();
        $found[] = $status === $expected
            ? null
            : sprintf('%s · %s: esperado %d, recibido %d', $route, $actor, $expected, $status);
    }

    foreach ($allowed as $actor) {
        $response = Api::as($tokens[$actor])->call($method, $uri, $body);
        $status = $response->getStatusCode();
        $found[] = $status < 500 && ! \in_array($status, [401, 403], true)
            ? null
            : sprintf(
                '%s · %s: autorizado, recibido %d %s',
                $route,
                $actor,
                $status,
                mb_substr((string) $response->getContent(), 0, 300),
            );
    }

    return array_values(array_filter($found, static fn (?string $line): bool => $line !== null));
}

it('declara en la matriz toda ruta autenticada del router, y ninguna que no exista', function (): void {
    $declared = array_keys(AuthorizationMatrix::routes());
    sort($declared);

    $router = authorizationMatrixRouterKeys(authenticated: true);

    expect(array_values(array_diff($router, $declared)))->toBe(
        [],
        'Ruta(s) autenticada(s) sin fila en la matriz de autorizacion: decide que actores la alcanzan y '
        .'anadela a AuthorizationMatrix::routes() (regla dura 18).',
    )->and(array_values(array_diff($declared, $router)))->toBe(
        [],
        'Fila(s) de la matriz que el router ya no sirve: quitalas.',
    );
})->group('RQ-07', 'RS-05');

it('encuentra en el router las rutas autenticadas que la matriz recorre', function (): void {
    // El control que impide que la prueba de arriba pase sobre un router vacio.
    expect(authorizationMatrixRouterKeys(authenticated: true))->toHaveCount(\count(AuthorizationMatrix::routes()))
        ->and(\count(AuthorizationMatrix::routes()))->toBeGreaterThan(90);
})->group('RQ-07');

it('no deja ninguna ruta publica fuera de la lista cerrada', function (): void {
    // Una ruta que se olvide de `auth:sanctum` no aparece en la matriz: aparece
    // aqui, y rompe la prueba.
    $public = AuthorizationMatrix::publicRoutes();
    sort($public);

    expect(authorizationMatrixRouterKeys(authenticated: false))->toBe($public);
})->group('RQ-07', 'RS-05');

it('no reconoce mas actores que los que la matriz recorre', function (): void {
    $named = array_values(array_unique(array_merge(...array_values(AuthorizationMatrix::routes()))));
    sort($named);

    $actors = AuthorizationMatrix::actors();
    sort($actors);

    expect($named)->toBe($actors);
})->group('RQ-07');

it('responde a cada actor, y sin token, lo que la matriz dice de la ruta', function (string $route): void {
    $installation = AuthorizationMatrix::installation();
    $tokens = AuthorizationMatrix::tokens($installation);

    $discrepancies = authorizationMatrixDiscrepanciesOf($route, $installation, $tokens);

    expect($discrepancies)->toBe([], implode("\n", $discrepancies))
        ->and(DB::table('audit_log')->where('action', AuditAction::AccessDenied->value)->count())
        ->toBe(AUTHORIZATION_MATRIX_SCOPE_DENIALS[$route] ?? 0);
})->with(authorizationMatrixRoutes())->group('RQ-07', 'RS-05', 'RS-04', 'RS-06');

it('detecta la discrepancia cuando la matriz autoriza a quien la ruta deniega', function (): void {
    // El control de la prueba de arriba: con el auditor apuntado como si
    // pudiera leer la configuracion, la ruta tiene que salir con su linea. Sin
    // esto, un acumulador que nunca acumulara pasaria en verde.
    $installation = AuthorizationMatrix::installation();
    $tokens = AuthorizationMatrix::tokens($installation);
    $tokens[AuthorizationMatrix::ADMIN] = $tokens[AuthorizationMatrix::AUDITOR];

    $discrepancies = authorizationMatrixDiscrepanciesOf('GET api/v1/settings', $installation, $tokens);

    expect($discrepancies)->toHaveCount(1)
        ->and($discrepancies[0])->toStartWith('GET api/v1/settings · admin: autorizado, recibido 403');
})->group('RQ-07');

/*
 * ---------------------------------------------------------------------------
 * CADA AMBITO DE UN ACTOR ABRE ALGO (V4-QA-2 de la verificacion de la 2.2.0)
 * ---------------------------------------------------------------------------
 *
 * La matriz fija que recibe cada actor, pero no que cada ambito que lleva en el
 * token le sirva para algo. Un ambito concedido que ninguna ruta autorizada
 * consume es una de dos cosas: un permiso de mas que alguien activara sin
 * querer el dia que una ruta lo pida, o un menu del panel que se enciende con
 * ese ambito y lleva a un `403` (V2-PA-01: el auditor con `attendance:read` veia
 * la presencia en el menu y el servidor se la negaba).
 *
 * Los ambitos se leen del token que la matriz emite a cada actor
 * (`PersonalAccessToken::findToken(...)->abilities`), no de una copia escrita
 * aqui: con la copia, un ambito de mas en el emisor del quiosco, del portal o
 * de un rol pasaba esta prueba, que seguia mirando la lista de antes.
 */

/**
 * Los ambitos que lleva el token de cada actor de la matriz, tal como se
 * emitio.
 *
 * @return array<string, list<string>>
 */
function authorizationMatrixAbilitiesByActor(): array
{
    return array_map(
        static fn (string $token): array => array_values(array_map(
            strval(...),
            (array) PersonalAccessToken::findToken($token)?->abilities,
        )),
        AuthorizationMatrix::tokens(AuthorizationMatrix::installation()),
    );
}

/**
 * Las claves de la matriz cuyo middleware `ability:` acepta un ambito
 * (`CheckForAnyAbility`: la lista separada por comas significa «o»).
 *
 * @return list<string>
 */
function authorizationMatrixRoutesRequiring(string $ability): array
{
    $keys = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $declared = array_values(array_filter(
            $route->gatherMiddleware(),
            static fn (mixed $middleware): bool => \is_string($middleware) && str_starts_with($middleware, 'ability:'),
        ));
        $abilities = explode(',', substr((string) ($declared[0] ?? 'ability:'), \strlen('ability:')));

        $keys[] = \in_array($ability, $abilities, true) ? authorizationMatrixKeysOf($route) : [];
    }

    return array_values(array_unique(array_merge(...$keys)));
}

/**
 * Los pares «actor · ambito» que no abren ninguna ruta que la matriz le
 * autorice a ese actor.
 *
 * @param  array<string, list<string>>  $abilitiesByActor
 * @return list<string>
 */
function authorizationMatrixAbilitiesThatOpenNothing(array $abilitiesByActor): array
{
    $routes = AuthorizationMatrix::routes();
    $found = [];

    foreach ($abilitiesByActor as $actor => $abilities) {
        foreach ($abilities as $ability) {
            $opened = array_filter(
                authorizationMatrixRoutesRequiring($ability),
                static fn (string $key): bool => \in_array($actor, $routes[$key] ?? [], true),
            );
            $found[] = $opened === [] ? $actor.' · '.$ability : null;
        }
    }

    return array_values(array_filter($found));
}

it('no concede a ningun actor un ambito que no le abra ninguna ruta', function (): void {
    // La unica excepcion, a la vista: `audit:read` de `admin`. El contrato lo
    // dice («Hoy no lo exige ninguna ruta», `openapi.yaml`, `securitySchemes`)
    // y `SupportScope` lo razona: lo contempla la tabla del doc 02 §7.3 para el
    // visor del registro de auditoria, que aun no existe. La prueba siguiente
    // obliga a revisar la excepcion el dia que una ruta lo pida.
    $excepciones = [AuthorizationMatrix::ADMIN.' · '.TokenAbility::AUDIT_READ->value];

    $sinRuta = authorizationMatrixAbilitiesThatOpenNothing(authorizationMatrixAbilitiesByActor());

    expect(array_values(array_diff($sinRuta, $excepciones)))->toBe(
        [],
        'Ambito(s) concedido(s) que no abren ninguna ruta al actor que los lleva: '.implode(', ', array_diff($sinRuta, $excepciones)),
    )->and($sinRuta)->toBe($excepciones);
})->group('RQ-07', 'RF-ID-02');

it('mantiene audit:read sin ninguna ruta que lo exija', function (): void {
    // Si aparece el visor de auditoria, la excepcion de arriba sobra y hay que
    // decidir quien lo lee, incluido el acceso de soporte `read_only`, que
    // tambien lleva este ambito (`SupportScope`, ADR-020).
    expect(authorizationMatrixRoutesRequiring(TokenAbility::AUDIT_READ->value))->toBe([]);
})->group('RQ-07', 'RF-PD-11');

it('detecta un ambito concedido que no abre nada', function (): void {
    // El control: con los ambitos del auditor anteriores a la 2.2.1, los dos
    // que perdio salen en la lista. Sin esto, una funcion que nunca encontrara
    // nada pasaria la primera prueba.
    $sinRuta = authorizationMatrixAbilitiesThatOpenNothing([
        AuthorizationMatrix::AUDITOR => ManagementUsers::legacyAuditorAbilities(),
    ]);

    expect($sinRuta)->toBe([
        AuthorizationMatrix::AUDITOR.' · '.TokenAbility::ATTENDANCE_READ->value,
        AuthorizationMatrix::AUDITOR.' · '.TokenAbility::AUDIT_READ->value,
    ]);
})->group('RQ-07', 'RF-ID-02');
