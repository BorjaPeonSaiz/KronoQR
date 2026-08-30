<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * A quien le toca revisar lo que le pasa a un empleado (RF-PR-01, doc 01 §5.5:
 * `departments.manager_user_id`).
 *
 * Es una consulta y no una regla: quien responde de un departamento lo decide
 * la plantilla, no el cumplimiento. Por eso vive detras de un puerto y devuelve
 * `null` sin drama cuando el departamento no tiene responsable asignado — la
 * incidencia se abre igual y queda sin asignar (doc 01 §5.5). Descartarla seria
 * perder un hallazgo por un hueco de configuracion.
 */
interface IncidentAssignment
{
    /**
     * `users.id` del responsable del departamento del empleado, o `null` si no
     * hay departamento, no hay responsable o la cuenta esta desactivada.
     */
    public function responsibleFor(string $employeeUuid): ?int;
}
