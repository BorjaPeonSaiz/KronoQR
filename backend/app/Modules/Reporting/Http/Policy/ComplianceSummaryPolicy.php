<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Reporting\Domain\ValueObject\ComplianceSummary;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver las alertas de cumplimiento (**RF-PA-06**, Anexo B del doc 01,
 * regla dura 18).
 *
 * ## «manager+», que aqui son tres roles
 *
 * `{admin, rrhh, responsable_departamento}`, el mismo conjunto que la presencia en
 * vivo y que la bandeja de incidencias, y el ultimo entra **acotado**: la consulta
 * le devuelve su gente y solo su gente (RF-ID-03), tambien en los recuentos. Es
 * deliberado que sea el mismo conjunto que la bandeja: esta pantalla es lo que un
 * responsable mira **despues** de la bandeja, y un conjunto distinto significaria
 * que alguien puede resolver una incidencia de descanso corto sin poder ver cuantas
 * hay.
 *
 * Escrito asi y no como `{@see}` a las hermanas porque Pint resolveria la
 * referencia a un `use`, y un `use` de otro modulo es la frontera del §1.6 que
 * Deptrac rechaza. Las de este mismo directorio son `LivePresencePolicy`,
 * `PeriodReportPolicy` y `WorkDayJournalPolicy`.
 *
 * ## Por que el `auditor` no esta, teniendo el ambito
 *
 * `auditor` lleva `attendance:read` en su token (§7.3) y aun asi recibe `403`,
 * exactamente igual que en la presencia en vivo. No es una contradiccion: es para
 * lo que sirven las dos comprobaciones del §7.3. Lo que ese rol necesita —el
 * registro para un requerimiento— se sirve por `GET /reports/legal-export`, que es
 * suyo. **Auditar es mirar lo que quedo escrito, no la gestion del dia**, y esta
 * pantalla es gestion: lo que enseña es trabajo pendiente de RRHH.
 *
 * ## La policy es la mitad de la autorizacion
 *
 * La otra es el ambito `attendance:read`, que el middleware `ability` verifica
 * antes. Con las dos, un token de quiosco no llega aqui aunque su portador tuviera
 * rol —no tiene el ambito— y una cuenta con el ambito pero sin rol no ve a nadie.
 *
 * **Se registra contra {@see ComplianceSummary}, que es un objeto de dominio y no
 * un modelo Eloquent.** Asi la autorizacion se decide **antes** de tocar la base
 * de datos: declarada sobre una fila, habria que cargarla para poder preguntar si
 * se puede leer.
 */
final class ComplianceSummaryPolicy
{
    /**
     * Roles que pueden ver el cumplimiento de terceros («manager+»).
     *
     * Metodo y no constante por lo mismo que en las hermanas: el conjunto puede
     * cambiar, y lo que no cambia —el alcance— se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function watchers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::RESPONSABLE_DEPARTAMENTO];
    }

    /** `GET /api/v1/compliance/summary`. */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::watchers());
    }
}
