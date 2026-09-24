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
 * ## Y NUNCA UN ACCESO DE SOPORTE DEL FABRICANTE (decision del 24-09-2026)
 *
 * Regla dura 16 y ADR-020. `SupportScope::ReadOnly` lleva `attendance:read` y
 * `SupportScope::actsAs()` devuelve `admin` ante las policies, asi que sin esta
 * comprobacion **pasa el middleware y pasa la lista de roles**: hasta esta
 * decision, un token del fabricante veia el panel de presencia entero.
 *
 * Y lo que ese panel enseña no es el registro horario: es **quien esta dentro
 * del hotel ahora mismo, con nombre y desde que hora**. Eso es vigilancia en
 * tiempo real de la plantilla del cliente, y **no tiene ningun valor
 * diagnostico**: la incidencia para la que existe el alcance `read_only` —«a
 * esta persona le salen ocho horas y deberian ser nueve»— se mira sobre
 * `GET /employees/{uuid}/workdays`, que son los datos ya escritos y auditados
 * como divulgacion. Saber quien esta en la cocina a las 21:40 no ayuda a
 * cuadrar un calculo de horas de la semana pasada.
 *
 * Cierra tambien **la suscripcion a los canales de presencia**, porque
 * `routes/channels.php` pregunta por esta misma habilidad: si no, el fabricante
 * se quedaria sin la foto y conservaria el flujo, que es peor.
 *
 * Mismo patron y misma via que `Workforce\Http\Policy\AbsencePolicy`,
 * `Product\Http\Policy\DataExportPolicy`, `SettingsPolicy` y
 * `ReportExportPolicy`: la pregunta va por el puerto
 * {@see ManagementActor::isSupportActor()}, porque `Reporting` no puede importar
 * nada de `Identity` ni de `Product` (doc 02 §1.6, verificado por Deptrac).
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
     * Si quien pregunta es una cuenta **de la organizacion del cliente** con uno
     * de los roles indicados.
     *
     * Las dos condiciones en un solo sitio para que ninguna habilidad futura se
     * pueda escribir olvidando la primera: un `actsAs()` suelto en un metodo
     * nuevo volveria a abrirle al fabricante la vigilancia en vivo del hotel, y
     * no se notaria al leerlo.
     */
    private static function isCustomerStaff(ManagementActor $actor, UserRole ...$roles): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...$roles);
    }

    /**
     * `GET /api/v1/attendance/live` y la suscripcion a los canales de presencia.
     */
    public function view(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::watchers());
    }
}
