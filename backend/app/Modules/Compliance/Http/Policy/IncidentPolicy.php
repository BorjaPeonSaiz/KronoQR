<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Policy;

use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien trabaja la bandeja de incidencias (**RF-PA-05**, Anexo B del doc 01,
 * regla dura 18).
 *
 * ## «manager+», que aqui son tres roles
 *
 * `{admin, rrhh, responsable_departamento}` desde la tarea 2.1. El ultimo entra
 * **acotado**: el listado le devuelve las incidencias de su gente y solo de su
 * gente (RF-ID-03), y resolver una de otro departamento es `403` con asiento.
 *
 * Escrito asi y no como `{@see}` a las policies hermanas porque Pint resolveria
 * la referencia a un `use`, y un `use` de otro modulo es la frontera del §1.6 que
 * Deptrac rechaza. Las hermanas son `Workforce\Http\Policy\EmployeePolicy`,
 * `Attendance\Http\Policy\ShiftEntryPolicy` y
 * `Reporting\Http\Policy\LivePresencePolicy`.
 *
 * ## Por que el `auditor` no esta
 *
 * Dos motivos, y cada uno bastaria. El primero es que el §7.3 no le concede el
 * ambito `incidents:*`, asi que ni llega al middleware; el segundo es este: el
 * Anexo B no lo pone en «manager+». Auditar es mirar lo que quedo escrito, no
 * decidir que se hace con lo que esta pendiente — y **resolver** es ademas
 * escribir sobre el registro de una persona.
 *
 * ## Ver y resolver son la misma potestad, a proposito
 *
 * Los dos metodos devuelven lo mismo y no es un descuido: el ambito `incidents:*`
 * es uno solo —«consultar y resolver incidencias»— y no existe en el §7.3 un
 * `incidents:read`. Separar la policy sin separar el ambito daria una frontera
 * que solo existe en PHP y que nadie podria conceder ni retirar. Estan escritos
 * como dos metodos para que la matriz de autorizacion negativa pruebe cada
 * endpoint por separado, que es lo que exige la regla dura 18.
 *
 * **Se registra contra {@see Incident}, que es el modelo de dominio y no un
 * modelo Eloquent.** Asi la autorizacion se decide **antes** de tocar la base de
 * datos: declarada sobre una fila, habria que cargarla para poder preguntar si se
 * puede leer.
 */
final class IncidentPolicy
{
    /**
     * Roles que trabajan la bandeja («manager+»).
     *
     * Metodo y no constante por lo mismo que en las hermanas: el conjunto cambio
     * en la tarea 2.1 y puede volver a cambiar, y lo que no cambia —el alcance—
     * se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function reviewers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::RESPONSABLE_DEPARTAMENTO];
    }

    /** `GET /api/v1/incidents`. */
    public function viewAny(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::reviewers());
    }

    /** `POST /api/v1/incidents/{id}/resolve`. */
    public function resolve(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::reviewers());
    }
}
