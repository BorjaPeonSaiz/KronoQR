<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * **¿Quien programo esta baja?** (RN-14, RL-04, ADR-062 §4).
 *
 * Antes de que la tarea del sistema haga efectiva una baja programada, mira el
 * ultimo asiento de programacion o anulacion de esa persona en `audit_log`. Si
 * no es una programacion con la misma fecha de cese, la fecha no la puso nadie
 * por el producto —una escritura directa en `employees.terminated_at`— y la tarea
 * no revoca tarjetas ni asienta en nombre de `system` una baja que nadie pidio.
 *
 * Vive en `Shared` porque la pregunta es de `Workforce` y la respuesta esta en
 * `Compliance`, y ninguno de los dos puede importar al otro (ADR-025). Solo
 * lectura; se llama con la cadena de auditoria tomada.
 */
interface ScheduledOffboardingRecord
{
    /**
     * La fecha de cese (`Y-m-d`) del ultimo `employee.offboarding_scheduled` de
     * la persona, o `null` si no lo hay o si lo ultimo fue una anulacion.
     */
    public function lastScheduledTerminationOf(string $employeeUuid): ?string;
}
