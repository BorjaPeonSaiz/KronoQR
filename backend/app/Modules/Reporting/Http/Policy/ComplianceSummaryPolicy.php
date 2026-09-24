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
 * ## Y NUNCA UN ACCESO DE SOPORTE DEL FABRICANTE (decision del 24-09-2026)
 *
 * Regla dura 16 y ADR-020. `SupportScope::ReadOnly` lleva `attendance:read` y
 * `SupportScope::actsAs()` devuelve `admin` ante las policies, asi que sin esta
 * comprobacion **pasa el middleware y pasa la lista de roles**: hasta esta
 * decision, un token del fabricante leia el resumen entero.
 *
 * Y lo que este resumen es, es **un listado de incumplimientos por persona de
 * toda la plantilla** —descanso corto, jornada excedida— sin acotar por
 * departamento, porque el actor de soporte se presenta como `admin`. Es decir:
 * el peor conjunto posible de este modulo, la lista de quien lleva mal las
 * horas en el hotel del cliente, servida al fabricante de una vez.
 *
 * **Y no hace falta para diagnosticar nada.** La incidencia para la que existe
 * el alcance `read_only` es «a esta persona le salen ocho horas y deberian ser
 * nueve», y eso se mira en `GET /employees/{uuid}/workdays` de la persona del
 * caso, que queda auditado como divulgacion. El resumen no da una hora mas: da
 * un juicio sobre las horas de todos los demas.
 *
 * Mismo patron y misma via que `Workforce\Http\Policy\AbsencePolicy`,
 * `Product\Http\Policy\DataExportPolicy`, `SettingsPolicy` y
 * `ReportExportPolicy`: la pregunta va por el puerto
 * {@see ManagementActor::isSupportActor()}, porque `Reporting` no puede importar
 * nada de `Identity` ni de `Product` (doc 02 §1.6, verificado por Deptrac).
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

    /**
     * Si quien pregunta es una cuenta **de la organizacion del cliente** con uno
     * de los roles indicados.
     *
     * Las dos condiciones en un solo sitio para que ninguna habilidad futura se
     * pueda escribir olvidando la primera: un `actsAs()` suelto en un metodo
     * nuevo volveria a servirle al fabricante la lista de incumplimientos de la
     * plantilla, y no se notaria al leerlo.
     */
    private static function isCustomerStaff(ManagementActor $actor, UserRole ...$roles): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...$roles);
    }

    /** `GET /api/v1/compliance/summary`. */
    public function view(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::watchers());
    }
}
