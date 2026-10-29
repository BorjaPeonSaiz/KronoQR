<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * Las series de la tarea que aplica las bajas programadas (RN-14, ADR-062 §4).
 *
 * - `kronoqr_offboardings_applied_total{outcome}`: cada persona que la pasada
 *   trata, por desenlace —`applied`, `deferred` (la jornada del cese aun puede
 *   crecer) o `failed`—. Sin uuid: la persona va al log, nunca a la metrica.
 * - `kronoqr_scheduled_offboardings_overdue`: cuantas bajas programadas siguen
 *   sin aplicar mas de un dia despues de su cese, al terminar la pasada. De ella
 *   cuelga la alerta `ScheduledOffboardingOverdue`.
 *
 * El adaptador no lanza: una metrica que no se escribe no puede deshacer una
 * baja ya confirmada.
 */
interface OffboardingMetrics
{
    public function offboardingProcessed(string $outcome): void;

    public function overdueScheduledOffboardings(int $count): void;
}
