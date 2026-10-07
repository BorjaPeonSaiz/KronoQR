<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

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
 * Los accesos de soporte del fabricante no estan: sus tres alcances se recorren
 * sobre el mismo router en `Tests\Feature\Product\SupportScopeRoutesTest`.
 *
 * ## Lo que NO afirma
 *
 * Que el `403` deje asiento: el doc 02 §9.4 solo lo exige cuando se deniega por
 * alcance, y eso lo prueban `DepartmentScopeTest`, `IncidentScopeTest` y
 * `AbsenceScopeTest`. Ni que el responsable vea solo su departamento: aqui el
 * empleado de la ruta es de su departamento a proposito, para que lo que se
 * mida sea el rol y no el alcance.
 */

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
});

const AUTHORIZATION_MATRIX_ADMIN = 'admin';

const AUTHORIZATION_MATRIX_RRHH = 'rrhh';

const AUTHORIZATION_MATRIX_RESPONSABLE = 'responsable';

const AUTHORIZATION_MATRIX_AUDITOR = 'auditor';

const AUTHORIZATION_MATRIX_EMPLEADO = 'empleado';

const AUTHORIZATION_MATRIX_QUIOSCO = 'quiosco';

const AUTHORIZATION_MATRIX_2FA_PENDIENTE = '2fa pendiente';

/** El `uuid` de las rutas que no son de un empleado: no existe, y da igual. */
const AUTHORIZATION_MATRIX_UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

/**
 * Los actores que pueden portar un token en una instalacion.
 *
 * @return list<string>
 */
function authorizationMatrixActors(): array
{
    return [
        AUTHORIZATION_MATRIX_ADMIN,
        AUTHORIZATION_MATRIX_RRHH,
        AUTHORIZATION_MATRIX_RESPONSABLE,
        AUTHORIZATION_MATRIX_AUDITOR,
        AUTHORIZATION_MATRIX_EMPLEADO,
        AUTHORIZATION_MATRIX_QUIOSCO,
        AUTHORIZATION_MATRIX_2FA_PENDIENTE,
    ];
}

/**
 * LA MATRIZ: cada ruta autenticada de la API y los actores que la alcanzan.
 *
 * La clave es la que da el router —verbo y URI sin barra inicial— para que la
 * comparacion con `Router::getRoutes()` sea literal. Todo actor que no aparece
 * en la lista de una ruta tiene que recibir `403` (o el `401` de
 * {@see authorizationMatrixUnauthenticatedDenials()}).
 *
 * Es el Anexo B del doc 01 en forma de tabla comprobable. Cambiar una fila es
 * cambiar quien ve los datos de la plantilla: se hace a la vista, en revision, y
 * con el contrato modificado antes.
 *
 * @return array<string, list<string>>
 */
function authorizationMatrix(): array
{
    $admin = AUTHORIZATION_MATRIX_ADMIN;
    $rrhh = AUTHORIZATION_MATRIX_RRHH;
    $responsable = AUTHORIZATION_MATRIX_RESPONSABLE;
    $auditor = AUTHORIZATION_MATRIX_AUDITOR;
    $empleado = AUTHORIZATION_MATRIX_EMPLEADO;
    $quiosco = AUTHORIZATION_MATRIX_QUIOSCO;
    $pendiente = AUTHORIZATION_MATRIX_2FA_PENDIENTE;

    return [
        // Fichaje y quiosco: solo el token de dispositivo (RS-04).
        'POST api/v1/scan' => [$quiosco],
        'POST api/v1/scan/batch' => [$quiosco],
        'POST api/v1/scan/pin' => [$quiosco],
        'POST api/v1/scan/discarded' => [$quiosco],
        'GET api/v1/kiosk/roster' => [$quiosco],
        'POST api/v1/kiosk/heartbeat' => [$quiosco],

        // Emparejamiento y flota: solo admin (tarea 5.6).
        'POST api/v1/kiosk/pair/confirm' => [$admin],
        'GET api/v1/devices' => [$admin],
        'POST api/v1/devices/{uuid}/unpair' => [$admin],

        // Correcciones (RF-AT-08): anular es solo de rrhh y admin.
        'POST api/v1/shift-entries' => [$admin, $rrhh, $responsable],
        'PATCH api/v1/shift-entries/{uuid}' => [$admin, $rrhh, $responsable],
        'POST api/v1/shift-entries/{uuid}/void' => [$admin, $rrhh],

        // Lectura de presencia, cumplimiento, jornadas e incidencias: manager+.
        'GET api/v1/attendance/live' => [$admin, $rrhh, $responsable],
        'GET api/v1/compliance/summary' => [$admin, $rrhh, $responsable],
        'GET api/v1/employees/{uuid}/workdays' => [$admin, $rrhh, $responsable],
        'GET api/v1/incidents' => [$admin, $rrhh, $responsable],
        'POST api/v1/incidents/{id}/resolve' => [$admin, $rrhh, $responsable],
        // El canal global de presencia: el responsable solo firma el de sus
        // departamentos, y el auditor tiene el ambito pero no el rol.
        'GET api/v1/broadcasting/auth' => [$admin, $rrhh],
        'POST api/v1/broadcasting/auth' => [$admin, $rrhh],

        // Informes: rrhh y admin; la exportacion legal, tambien el auditor.
        'GET api/v1/reports/period' => [$admin, $rrhh],
        'GET api/v1/reports/period/export' => [$admin, $rrhh],
        'GET api/v1/reports/legal-export' => [$admin, $rrhh, $auditor],
        'GET api/v1/reports/payroll-export' => [$admin, $rrhh],
        'GET api/v1/reports/adoption' => [$admin, $rrhh],
        'GET api/v1/reports/adoption/export' => [$admin, $rrhh],
        'GET api/v1/reports/exports' => [$admin, $rrhh],
        'POST api/v1/reports/exports' => [$admin, $rrhh],
        'GET api/v1/reports/exports/{uuid}' => [$admin, $rrhh],

        // Plantilla: lee el responsable; escribe rrhh y admin.
        'GET api/v1/employees' => [$admin, $rrhh, $responsable],
        'GET api/v1/employees/{uuid}' => [$admin, $rrhh, $responsable],
        'POST api/v1/employees' => [$admin, $rrhh],
        'POST api/v1/employees/import' => [$admin, $rrhh],
        'PATCH api/v1/employees/{uuid}' => [$admin, $rrhh],
        'POST api/v1/employees/{uuid}/offboard' => [$admin, $rrhh],
        'POST api/v1/employees/{uuid}/pin/reset' => [$admin, $rrhh],
        'POST api/v1/employees/{uuid}/pin/deliver' => [$admin, $rrhh],
        'GET api/v1/employees/{uuid}/contracts' => [$admin, $rrhh],
        'POST api/v1/employees/{uuid}/contracts' => [$admin, $rrhh],
        'GET api/v1/absences' => [$admin, $rrhh, $responsable],
        'GET api/v1/absences/{uuid}' => [$admin, $rrhh, $responsable],
        'POST api/v1/absences' => [$admin, $rrhh],
        'POST api/v1/absences/import' => [$admin, $rrhh],
        'PATCH api/v1/absences/{uuid}' => [$admin, $rrhh],
        'POST api/v1/absences/{uuid}/void' => [$admin, $rrhh],
        // Lectura de departamentos abierta a toda cuenta de gestion (2.2.0).
        'GET api/v1/departments' => [$admin, $rrhh, $responsable, $auditor],
        'GET api/v1/departments/{id}' => [$admin, $rrhh, $responsable, $auditor],
        'POST api/v1/departments' => [$admin, $rrhh],
        'PATCH api/v1/departments/{id}' => [$admin, $rrhh],
        'GET api/v1/site' => [$admin, $rrhh],
        'PATCH api/v1/site' => [$admin, $rrhh],

        // Credenciales (ADR-034).
        'POST api/v1/credentials' => [$admin, $rrhh],
        'GET api/v1/credentials/status' => [$admin, $rrhh],
        'GET api/v1/credentials/instructions-sheet' => [$admin, $rrhh],
        'POST api/v1/credentials/print-batch' => [$admin, $rrhh],
        'POST api/v1/credentials/{uuid}/print' => [$admin, $rrhh],
        'POST api/v1/credentials/{uuid}/deliver' => [$admin, $rrhh],
        'POST api/v1/credentials/{uuid}/revoke' => [$admin, $rrhh],

        // La instalacion: solo admin.
        'GET api/v1/settings' => [$admin],
        'PATCH api/v1/settings' => [$admin],
        'GET api/v1/compliance-profile' => [$admin],
        'PATCH api/v1/compliance-profile' => [$admin],
        'GET api/v1/license' => [$admin],
        'POST api/v1/license/activate' => [$admin],
        'POST api/v1/diagnostics/bundle' => [$admin],
        'GET api/v1/diagnostics/errors' => [$admin],
        'POST api/v1/diagnostics/errors/{id}/resolve' => [$admin],
        'GET api/v1/support/grants' => [$admin],
        'POST api/v1/support/grants' => [$admin],
        'DELETE api/v1/support/grants/{uuid}' => [$admin],
        'GET api/v1/data-export' => [$admin],
        'POST api/v1/data-export' => [$admin],
        'GET api/v1/data-export/{uuid}/download' => [$admin],
        'POST api/v1/setup/site' => [$admin],
        'GET api/v1/setup/steps' => [$admin],
        'PUT api/v1/setup/steps/{step}' => [$admin],
        'POST api/v1/setup/complete' => [$admin],
        'GET api/v1/management-accounts' => [$admin],
        'POST api/v1/management-accounts' => [$admin],
        'POST api/v1/management-accounts/{uuid}/deactivate' => [$admin],
        'POST api/v1/management-accounts/{uuid}/password/reset' => [$admin],
        'POST api/v1/management-accounts/{uuid}/two-factor/reset' => [$admin],

        // Sesion propia.
        'POST api/v1/auth/2fa/verify' => [$pendiente],
        'POST api/v1/auth/2fa/enrol' => [$pendiente],
        'POST api/v1/auth/2fa/confirm' => [$pendiente],
        // Cerrar la propia sesion no lee ni escribe nada de nadie: todo portador.
        'POST api/v1/auth/logout' => [$admin, $rrhh, $responsable, $auditor, $empleado, $quiosco, $pendiente],
        'GET api/v1/auth/me' => [$admin, $rrhh, $responsable, $auditor],
        'POST api/v1/auth/password' => [$admin, $rrhh, $responsable, $auditor],
        'GET api/v1/me/workdays' => [$empleado],
        'GET api/v1/me/export' => [$empleado],
        'POST api/v1/me/logout' => [$empleado],
        // Quien sufre el error lo reporta: toda sesion menos el dispositivo, que
        // tiene su canal en el latido (`ErrorEventPolicy::report`). La sesion
        // pendiente de segundo factor es un `ManagementActor` y la policy la
        // acepta.
        'POST api/v1/client-errors' => [$admin, $rrhh, $responsable, $auditor, $empleado, $pendiente],
    ];
}

/**
 * Las denegaciones que el contrato fija en `401` y no en `403`, con su motivo.
 *
 * `GET /auth/me` y `POST /auth/password` son de cuentas de gestion: un portador
 * que no es una cuenta completa no tiene a nadie de quien hablar, y
 * `openapi.yaml` declara `401` para la sesion pendiente de segundo factor en las
 * dos. El quiosco y el portal tampoco son cuentas en `/auth/me`. Lista cerrada:
 * cualquier otra denegacion es `403`.
 *
 * @return array<string, list<string>>
 */
function authorizationMatrixUnauthenticatedDenials(): array
{
    return [
        'GET api/v1/auth/me' => [AUTHORIZATION_MATRIX_EMPLEADO, AUTHORIZATION_MATRIX_QUIOSCO, AUTHORIZATION_MATRIX_2FA_PENDIENTE],
        'POST api/v1/auth/password' => [AUTHORIZATION_MATRIX_2FA_PENDIENTE],
    ];
}

/**
 * Las rutas de la API sin `auth:sanctum`, lista cerrada.
 *
 * Cada una es publica por necesidad: las sondas (`/health`, `/ready`), los dos
 * accesos, el emparejamiento antes de tener token, la marca que pinta la
 * pantalla de acceso, el asistente antes de que exista el primer admin y la
 * descarga del enlace de un solo uso de ADR-041. Lo que las protege tiene sus
 * pruebas propias.
 *
 * @return list<string>
 */
function authorizationMatrixPublicRoutes(): array
{
    return [
        'GET api/v1/branding',
        'GET api/v1/branding/logo',
        'GET api/v1/health',
        'GET api/v1/ready',
        'GET api/v1/reports/exports/{uuid}/download',
        'GET api/v1/setup/status',
        'POST api/v1/auth/login',
        'POST api/v1/kiosk/pair',
        'POST api/v1/kiosk/pair/claim',
        'POST api/v1/me/login',
        'POST api/v1/setup/administrator',
    ];
}

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
 * Las celdas denegadas de la matriz: ruta, actor y estado esperado.
 *
 * @return array<string, array{0: string, 1: string, 2: int}>
 */
function authorizationMatrixDenials(): array
{
    $cells = [];
    $unauthenticated = authorizationMatrixUnauthenticatedDenials();

    foreach (authorizationMatrix() as $route => $allowed) {
        foreach (array_diff(authorizationMatrixActors(), $allowed) as $actor) {
            $status = \in_array($actor, $unauthenticated[$route] ?? [], true) ? 401 : 403;
            $cells[$route.' · '.$actor] = [$route, $actor, $status];
        }
    }

    return $cells;
}

/**
 * Las celdas autorizadas de la matriz.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function authorizationMatrixGrants(): array
{
    $cells = [];

    foreach (authorizationMatrix() as $route => $allowed) {
        foreach ($allowed as $actor) {
            $cells[$route.' · '.$actor] = [$route, $actor];
        }
    }

    return $cells;
}

/**
 * Cada ruta autenticada, para la prueba sin token.
 *
 * @return array<string, array{0: string}>
 */
function authorizationMatrixRoutes(): array
{
    $routes = array_keys(authorizationMatrix());

    return array_combine($routes, array_map(static fn (string $route): array => [$route], $routes));
}

/**
 * La instalacion minima: un centro, el departamento del responsable y una
 * persona en el. Las rutas de un empleado se piden con esa persona, para que al
 * responsable lo juzgue su rol y no su alcance.
 *
 * @return array{employee: string, responsable: string}
 */
function authorizationMatrixInstallation(): array
{
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    Department::query()->whereKey($department)->update(['manager_user_id' => $responsable->id]);

    return [
        'employee' => WorkforceFixtures::employee($site, $department),
        'responsable' => ManagementUsers::tokenFor($responsable),
    ];
}

/**
 * El token de cada actor, emitido como lo emite el producto.
 *
 * El `default` lanza: un actor nuevo sin token revienta aqui y no pasa por
 * denegado.
 *
 * @param  array{employee: string, responsable: string}  $installation
 */
function authorizationMatrixTokenOf(string $actor, array $installation): string
{
    return match ($actor) {
        AUTHORIZATION_MATRIX_ADMIN => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)),
        AUTHORIZATION_MATRIX_RRHH => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        AUTHORIZATION_MATRIX_RESPONSABLE => $installation['responsable'],
        AUTHORIZATION_MATRIX_AUDITOR => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)),
        AUTHORIZATION_MATRIX_EMPLEADO => PortalLogins::open($installation['employee']),
        AUTHORIZATION_MATRIX_QUIOSCO => AttendanceFixtures::tokenFor(
            AttendanceFixtures::device(WorkforceFixtures::onlySiteId())['id'],
        ),
        AUTHORIZATION_MATRIX_2FA_PENDIENTE => ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        default => throw new InvalidArgumentException('La matriz nombra un actor sin token: '.$actor.'.'),
    };
}

/**
 * Verbo y URI concreta de una clave de la matriz.
 *
 * Las rutas `employees/{uuid}` se piden con la persona del departamento del
 * responsable; el resto, con un `uuid` que no existe: la autorizacion tiene que
 * cortar antes de mirar si existe.
 *
 * @param  array{employee: string, responsable: string}  $installation
 * @return array{0: string, 1: string}
 */
function authorizationMatrixRequestOf(string $route, array $installation): array
{
    [$method, $uri] = explode(' ', $route, 2);

    $employeeUri = str_starts_with($uri, 'api/v1/employees/{uuid}');

    $concrete = str_replace(
        ['{uuid}', '{id}', '{step}'],
        [$employeeUri ? $installation['employee'] : AUTHORIZATION_MATRIX_UUID, '1', 'site'],
        $uri,
    );

    return [$method, '/'.$concrete];
}

it('declara en la matriz toda ruta autenticada del router, y ninguna que no exista', function (): void {
    $declared = array_keys(authorizationMatrix());
    sort($declared);

    $router = authorizationMatrixRouterKeys(authenticated: true);

    expect(array_values(array_diff($router, $declared)))->toBe(
        [],
        'Ruta(s) autenticada(s) sin fila en la matriz de autorizacion: decide que actores la alcanzan y '
        .'anadela a authorizationMatrix() (regla dura 18).',
    )->and(array_values(array_diff($declared, $router)))->toBe(
        [],
        'Fila(s) de la matriz que el router ya no sirve: quitalas.',
    );
})->group('RQ-07', 'RS-05');

it('encuentra en el router las rutas autenticadas que la matriz recorre', function (): void {
    // El control que impide que la prueba de arriba pase sobre un router vacio.
    expect(authorizationMatrixRouterKeys(authenticated: true))->toHaveCount(\count(authorizationMatrix()))
        ->and(\count(authorizationMatrix()))->toBeGreaterThan(90);
})->group('RQ-07');

it('no deja ninguna ruta publica fuera de la lista cerrada', function (): void {
    // Una ruta que se olvide de `auth:sanctum` no aparece en la matriz: aparece
    // aqui, y rompe la prueba.
    $public = authorizationMatrixPublicRoutes();
    sort($public);

    expect(authorizationMatrixRouterKeys(authenticated: false))->toBe($public);
})->group('RQ-07', 'RS-05');

it('no reconoce mas actores que los que la matriz recorre', function (): void {
    $named = array_values(array_unique(array_merge(...array_values(authorizationMatrix()))));
    sort($named);

    $actors = authorizationMatrixActors();
    sort($actors);

    expect($named)->toBe($actors);
})->group('RQ-07');

it('deniega cada ruta a cada actor que la matriz no autoriza', function (string $route, string $actor, int $status): void {
    $installation = authorizationMatrixInstallation();
    $token = authorizationMatrixTokenOf($actor, $installation);
    [$method, $uri] = authorizationMatrixRequestOf($route, $installation);

    $response = Api::as($token)->call($method, $uri, authorizationMatrixBodyFor($route));

    $response->assertStatus($status);
})->with(authorizationMatrixDenials())->group('RQ-07', 'RS-05', 'RS-04', 'RS-06');

it('deja pasar a cada actor que la matriz autoriza', function (string $route, string $actor): void {
    $installation = authorizationMatrixInstallation();
    $token = authorizationMatrixTokenOf($actor, $installation);
    [$method, $uri] = authorizationMatrixRequestOf($route, $installation);

    $response = Api::as($token)->call($method, $uri, authorizationMatrixBodyFor($route));

    expect($response->getStatusCode())->not->toBeIn([401, 403], (string) $response->getContent());
})->with(authorizationMatrixGrants())->group('RQ-07');

it('responde 401 sin token en cada ruta autenticada', function (string $route): void {
    $installation = authorizationMatrixInstallation();
    [$method, $uri] = authorizationMatrixRequestOf($route, $installation);

    Api::guest()->call($method, $uri, authorizationMatrixBodyFor($route))
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(authorizationMatrixRoutes())->group('RQ-07', 'RS-05');
