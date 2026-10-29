<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use InvalidArgumentException;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\WorkforceFixtures;

/**
 * La matriz de autorizacion de la API: cada ruta autenticada y los actores que
 * la alcanzan, con los tokens de cada actor emitidos como los emite el producto
 * (regla dura 18, RQ-07).
 *
 * La recorre `Tests\Feature\AuthorizationMatrixTest`, que es donde se explica
 * por que existe y que afirma. Vive aqui, y no dentro de esa prueba, porque la
 * usan tambien los barridos del contrato (`tests/Contract/*SweepTest.php`,
 * Q3): el `403` de cada operacion se pide con un actor al que la matriz
 * deniega, y el `400`/`415` con uno al que autoriza. Una sola tabla para las
 * dos cosas, o un dia dirian cosas distintas.
 */
final class AuthorizationMatrix
{
    public const string ADMIN = 'admin';

    public const string RRHH = 'rrhh';

    public const string RESPONSABLE = 'responsable';

    public const string AUDITOR = 'auditor';

    public const string EMPLEADO = 'empleado';

    public const string QUIOSCO = 'quiosco';

    public const string TWO_FACTOR_PENDING = '2fa pendiente';

    /** El `uuid` de las rutas que no son de un empleado: no existe, y da igual. */
    public const string UUID = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

    /**
     * Los actores que pueden portar un token en una instalacion.
     *
     * @return list<string>
     */
    public static function actors(): array
    {
        return [
            self::ADMIN,
            self::RRHH,
            self::RESPONSABLE,
            self::AUDITOR,
            self::EMPLEADO,
            self::QUIOSCO,
            self::TWO_FACTOR_PENDING,
        ];
    }

    /**
     * LA MATRIZ: cada ruta autenticada de la API y los actores que la alcanzan.
     *
     * La clave es la que da el router —verbo y URI sin barra inicial— para que la
     * comparacion con `Router::getRoutes()` sea literal. Todo actor que no aparece
     * en la lista de una ruta tiene que recibir `403` (o el `401` de
     * {@see self::unauthenticatedDenials()}).
     *
     * Es el Anexo B del doc 01 en forma de tabla comprobable. Cambiar una fila es
     * cambiar quien ve los datos de la plantilla: se hace a la vista, en revision, y
     * con el contrato modificado antes.
     *
     * @return array<string, list<string>>
     */
    public static function routes(): array
    {
        $admin = self::ADMIN;
        $rrhh = self::RRHH;
        $responsable = self::RESPONSABLE;
        $auditor = self::AUDITOR;
        $empleado = self::EMPLEADO;
        $quiosco = self::QUIOSCO;
        $pendiente = self::TWO_FACTOR_PENDING;

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
            // departamentos, y el auditor no tiene ni el ambito ni el rol.
            'POST api/v1/broadcasting/auth' => [$admin, $rrhh],

            // Informes: rrhh y admin; la exportacion legal, tambien el auditor.
            'GET api/v1/reports/period' => [$admin, $rrhh],
            'GET api/v1/reports/period/export' => [$admin, $rrhh],
            'GET api/v1/reports/legal-export' => [$admin, $rrhh, $auditor],
            // Y la busqueda de la persona de esa exportacion, con los mismos tres
            // roles: quien exporta a una persona tiene que poder elegirla (RF-IN-05).
            'POST api/v1/reports/legal-export/employees/search' => [$admin, $rrhh, $auditor],
            'GET api/v1/reports/payroll-export' => [$admin, $rrhh],
            'GET api/v1/reports/adoption' => [$admin, $rrhh],
            'GET api/v1/reports/adoption/export' => [$admin, $rrhh],
            // El diferido admite al auditor desde la 2.3.0 (V5-PA-4): consulta,
            // cancela y descarga las suyas, que solo pueden ser `kind: legal`. Pedir
            // con el cuerpo vacio de esta matriz cae en la policy mas estrecha —la de
            // nomina— y le responde 403; su `kind: legal` lo prueba
            // `DeferredLongExportsTest`.
            'GET api/v1/reports/exports' => [$admin, $rrhh, $auditor],
            'POST api/v1/reports/exports' => [$admin, $rrhh],
            'GET api/v1/reports/exports/{uuid}' => [$admin, $rrhh, $auditor],
            'POST api/v1/reports/exports/{uuid}/cancel' => [$admin, $rrhh, $auditor],

            // Plantilla: lee el responsable; escribe rrhh y admin.
            'GET api/v1/employees' => [$admin, $rrhh, $responsable],
            'GET api/v1/employees/{uuid}' => [$admin, $rrhh, $responsable],
            'POST api/v1/employees' => [$admin, $rrhh],
            'POST api/v1/employees/import' => [$admin, $rrhh],
            'PATCH api/v1/employees/{uuid}' => [$admin, $rrhh],
            'POST api/v1/employees/{uuid}/offboard' => [$admin, $rrhh],
            'POST api/v1/employees/{uuid}/offboard/cancel' => [$admin, $rrhh],
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
            // Lectura de departamentos: quien lleva `employees:read` (2.2.0). El
            // auditor no desde la 2.2.1: solo la exportacion legal (RF-ID-02).
            'GET api/v1/departments' => [$admin, $rrhh, $responsable],
            'GET api/v1/departments/{id}' => [$admin, $rrhh, $responsable],
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
            'GET api/v1/support/grants/{uuid}/uses' => [$admin],
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
            // Cerrar la propia sesion: la gestion, tambien a medio entrar. El quiosco
            // y el portal, `403` (ADR-039): el portal cierra por `/me/logout`.
            'POST api/v1/auth/logout' => [$admin, $rrhh, $responsable, $auditor, $pendiente],
            'GET api/v1/auth/me' => [$admin, $rrhh, $responsable, $auditor],
            'POST api/v1/auth/password' => [$admin, $rrhh, $responsable, $auditor],
            'GET api/v1/me/workdays' => [$empleado],
            'GET api/v1/me/export' => [$empleado],
            'POST api/v1/me/logout' => [$empleado],
            // Quien sufre el error lo reporta: toda sesion completa menos el
            // dispositivo, que tiene su canal en el latido (`ErrorEventPolicy::report`).
            // La pendiente de segundo factor no ha terminado de entrar (RS-06).
            'POST api/v1/client-errors' => [$admin, $rrhh, $responsable, $auditor, $empleado],
        ];
    }

    /**
     * Las denegaciones que el contrato fija en `401` y no en `403`, con su motivo.
     *
     * `GET /auth/me` y `POST /auth/password` son de cuentas de gestion: un portador
     * que no es una cuenta completa no tiene a nadie de quien hablar, y
     * `openapi.yaml` declara `401` para la sesion pendiente de segundo factor en las
     * dos. El quiosco y el portal tampoco son cuentas en `/auth/me`.
     * `POST /client-errors` no exige ambito y la pendiente la para el mismo
     * `session.complete` de `/auth/me`, con el mismo `401` («todavia no has
     * terminado de entrar»). Lista cerrada: cualquier otra denegacion es `403`.
     *
     * @return array<string, list<string>>
     */
    public static function unauthenticatedDenials(): array
    {
        return [
            'GET api/v1/auth/me' => [self::EMPLEADO, self::QUIOSCO, self::TWO_FACTOR_PENDING],
            'POST api/v1/auth/password' => [self::TWO_FACTOR_PENDING],
            'POST api/v1/client-errors' => [self::TWO_FACTOR_PENDING],
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
    public static function publicRoutes(): array
    {
        return [
            'GET api/v1/branding',
            'GET api/v1/branding/logo',
            'GET api/v1/branding/manifest.webmanifest',
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
     * La instalacion minima: un centro, el departamento del responsable y una
     * persona en el. Las rutas de un empleado se piden con esa persona, para que al
     * responsable lo juzgue su rol y no su alcance.
     *
     * @return array{employee: string, responsable: string}
     */
    public static function installation(): array
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
     * El token de cada actor, emitido como lo emite el producto, una sola vez por
     * caso.
     *
     * @param  array{employee: string, responsable: string}  $installation
     * @return array<string, string>
     */
    public static function tokens(array $installation): array
    {
        $tokens = [];

        foreach (self::actors() as $actor) {
            $tokens[$actor] = self::tokenFor($actor, $installation);
        }

        return $tokens;
    }

    /**
     * El token de un actor, emitido como lo emite el producto.
     *
     * El `default` lanza: un actor nuevo sin token revienta aqui y no pasa por
     * denegado.
     *
     * @param  array{employee: string, responsable: string}  $installation
     */
    public static function tokenFor(string $actor, array $installation): string
    {
        return match ($actor) {
            self::ADMIN => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)),
            self::RRHH => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
            self::RESPONSABLE => $installation['responsable'],
            self::AUDITOR => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)),
            self::EMPLEADO => PortalLogins::open($installation['employee']),
            self::QUIOSCO => AttendanceFixtures::tokenFor(
                AttendanceFixtures::device(WorkforceFixtures::onlySiteId())['id'],
            ),
            self::TWO_FACTOR_PENDING => ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::ADMIN)),
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
    public static function requestOf(string $route, array $installation): array
    {
        [$method, $uri] = explode(' ', $route, 2);

        $employeeUri = str_starts_with($uri, 'api/v1/employees/{uuid}');

        $concrete = str_replace(
            ['{uuid}', '{id}', '{step}'],
            [$employeeUri ? $installation['employee'] : self::UUID, '1', 'site'],
            $uri,
        );

        return [$method, '/'.$concrete];
    }
}
