<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver quien esta fichado ahora mismo (**RF-PA-01**, Anexo B del doc
 * 01, regla dura 18).
 *
 * ## «manager+», que aqui son tres roles
 *
 * El Anexo B escribe `GET /api/v1/attendance/live` como `[rol: manager+]`, sin
 * la mitad `self` que si tiene el registro horario: la presencia de la plantilla
 * es una vista de gestion y no hay ninguna version «la mia» de ella. Desde la
 * tarea 2.1 el conjunto es `{admin, rrhh, responsable_departamento}`, y el
 * ultimo entra **acotado**: la consulta le devuelve su gente y solo su gente
 * (RF-ID-03), y el canal de WebSocket le firma sus departamentos y no el global.
 *
 * Escrito asi y no como `{@see}` a las otras policies porque Pint resolveria la
 * referencia a un `use`, y un `use` de otro modulo es la frontera del §1.6 que
 * Deptrac rechaza. Las hermanas son `Workforce\Http\Policy\EmployeePolicy`,
 * `Attendance\Http\Policy\ShiftEntryPolicy` y la de este mismo directorio,
 * `WorkDayJournalPolicy`.
 *
 * ## Por que el `auditor` no esta, teniendo el ambito
 *
 * `auditor` lleva `attendance:read` en su token (§7.3) y aun asi recibe `403`
 * aqui, exactamente igual que en `GET /employees/{uuid}/workdays`. No es una
 * contradiccion: es para lo que sirven las dos comprobaciones del §7.3. El Anexo
 * B no lo pone en `manager+`, y lo que ese rol necesita —el registro para un
 * requerimiento— se sirve por `GET /reports/legal-export`, que es suyo. Auditar
 * es mirar lo que quedo escrito, no mirar quien esta en la cocina ahora.
 *
 * ## La policy es la mitad de la autorizacion
 *
 * La otra es el ambito `attendance:read`, que el middleware `ability` verifica
 * antes. Con las dos, un token de quiosco no llega aqui aunque su portador
 * tuviera rol —no tiene el ambito— y una cuenta con el ambito pero sin rol no ve
 * a nadie.
 *
 * **Se registra contra {@see PresenceBoard}, que es un objeto de dominio y no un
 * modelo Eloquent.** Asi la autorizacion se decide **antes** de tocar la base de
 * datos: declarada sobre una fila, habria que cargarla para poder preguntar si
 * se puede leer.
 */
final class LivePresencePolicy
{
    /**
     * Roles que pueden ver la presencia de terceros («manager+»).
     *
     * Metodo y no constante por lo mismo que en las hermanas: el conjunto cambio
     * en la tarea 2.1 y puede volver a cambiar, y lo que no cambia —el alcance—
     * se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function watchers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::RESPONSABLE_DEPARTAMENTO];
    }

    /**
     * `GET /api/v1/attendance/live` y la suscripcion a los canales de presencia.
     */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::watchers());
    }
}
