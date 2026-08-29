<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Support\PresenceChannels;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * Autorizacion de los canales de la presencia en vivo (ADR-011, RF-ID-03, regla
 * dura 18).
 *
 * LA SUSCRIPCION A UN CANAL ES UN ENDPOINT MAS. Se autoriza con los mismos dos
 * controles que cualquier ruta del §7.3 y con el mismo rigor: el AMBITO del token
 * —`attendance:read`, que comprueba el middleware de la ruta de autorizacion en
 * `bootstrap/app.php`— y la POLICY del recurso, que aqui se pregunta contra
 * `PresenceBoard`, el mismo objeto que autoriza `GET /api/v1/attendance/live`.
 * Que sean el mismo objeto no es comodidad: si el canal tuviera una policy propia,
 * el dia que una de las dos cambiara habria una via en tiempo real hacia datos que
 * el endpoint ya no da.
 *
 * NINGUN CANAL ES PUBLICO. Los dos que hay son privados —en el cable viajan como
 * `private-presence.all` y `private-presence.department.3`— y sin firma del
 * servidor no se recibe absolutamente nada. Por eso `allowed_origins` de Reverb
 * puede quedarse abierto: abrir el socket desde cualquier origen no da acceso a
 * ningun dato.
 *
 * NO SON *PRESENCE CHANNELS* DE PUSHER, pese al nombre. Aquellos publican la
 * lista de quien esta suscrito; aqui lo que se difunde es la presencia **de los
 * empleados**, y quien mira el panel no se anuncia a nadie. Los callbacks
 * devuelven `bool` y no un array de datos de usuario, que es justo la diferencia.
 *
 * QUIEN NO ES UNA CUENTA DE GESTION NO ENTRA. Un token de quiosco, una sesion de
 * portal o una sesion pendiente de segundo factor no pasan el `ability` de la
 * ruta; y si llegaran, `ScopeGuard::scopeOf()` **falla cerrado** —alcance que no
 * alcanza a nadie— y la policy los rechaza por rol.
 */

/*
 * El canal de la instalacion entera. Solo alcance SIN restriccion: `admin`,
 * `rrhh` y —por ambito, no por policy— nadie mas.
 *
 * Un `responsable_departamento` recibe `false` aqui **aunque dirija todos los
 * departamentos que existen hoy**: su alcance es una lista y la lista puede
 * quedarse corta mañana, cuando se cree un departamento nuevo. Suscribirlo al
 * global le daria en tiempo real justo lo que RF-ID-03 le niega en el listado.
 */
Broadcast::channel(
    PresenceChannels::ALL,
    static function (mixed $user): bool {
        if (! Gate::forUser($user)->allows('view', PresenceBoard::class)) {
            return false;
        }

        return PresenceChannels::mayJoinAll(app(ScopeGuard::class)->scopeOf($user));
    },
);

/*
 * El canal de un departamento. Lo alcanzan las cuentas sin restriccion y el
 * responsable de ESE departamento.
 *
 * `{departmentId}` llega como cadena desde el nombre del canal —lo parte
 * Laravel— y se convierte aqui: un `presence.department.3abc` no puede
 * confundirse con el 3. `is_numeric` antes del casting, porque `(int) '3abc'` es
 * `3` y eso seria una autorizacion concedida a un canal que nadie pidio.
 */
Broadcast::channel(
    PresenceChannels::DEPARTMENT_PREFIX.'{departmentId}',
    static function (mixed $user, string $departmentId): bool {
        if (! Gate::forUser($user)->allows('view', PresenceBoard::class)) {
            return false;
        }

        if (! ctype_digit($departmentId)) {
            return false;
        }

        return PresenceChannels::mayJoinDepartment(
            app(ScopeGuard::class)->scopeOf($user),
            (int) $departmentId,
        );
    },
);
