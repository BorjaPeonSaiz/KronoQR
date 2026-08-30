<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Reporting\Application\Port\EmployeeAttribution;
use App\Modules\Reporting\Application\Port\WorkedTimeMetrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Suma a `worked_minutes_total{site,department}` los minutos de cada tramo que
 * se cierra (doc 02 §8.2, doc 01 §9.2, tarea 2.8).
 *
 * ## Por que aqui y no al leer el informe
 *
 * El porque completo esta en {@see WorkedTimeMetrics}. En corto: es un contador
 * de Prometheus, solo puede crecer, y el unico momento en el que se puede
 * incrementar sin mentir es cuando el hecho ocurre. Contarlo al leer el informe
 * sumaria las mismas horas cada vez que alguien abre la pantalla; contarlo al
 * recalcular `daily_totals` sumaria el dia entero otra vez en cada correccion,
 * porque la proyeccion se recalcula, no se incrementa (regla dura 7).
 *
 * ## `Attendance` no sabe que esto existe
 *
 * Emite y `Reporting` reacciona (doc 02 §1.6). Es la misma via por la que este
 * modulo difunde la presencia en vivo y por la que `Compliance` escribe el
 * asiento de `audit_log`, y la unica que las fronteras del §1.6 conceden.
 *
 * ## Encolado y despues del commit
 *
 * `ShouldQueue` con `$afterCommit = true`, igual que la difusion de presencia y
 * al contrario que los listeners de auditoria. Aquellos escriben en la misma
 * base de datos y **deben** entrar en la transaccion del fichaje —si el asiento
 * falla, el fichaje no se confirma (regla dura 6)—; este habla con Redis y
 * consulta el departamento, asi que sincrono contaria minutos de un fichaje que
 * todavia puede revertir y ademas metería dos viajes de red en el camino critico
 * (RNF-P-02).
 *
 * ## Y aun asi no puede romper un fichaje
 *
 * Las reglas duras 15 y 19 no admiten «normalmente no bloquea». Con una cola de
 * verdad este listener ya esta fuera del camino, pero la cola `sync` es una
 * configuracion legitima de una instalacion pequeña, y ahi corre dentro de la
 * peticion: un Redis caido convertiria un fichaje **ya escrito** en un `500`. El
 * adaptador de metricas ya calla sus fallos; esto atrapa ademas los de la
 * consulta del departamento.
 *
 * **Se anota `employee_uuid` y nunca el nombre** (regla dura 21).
 */
final class RecordWorkedMinutes implements ShouldQueue
{
    /**
     * Los eventos del fichaje se publican dentro de la transaccion del caso de
     * uso: sin esto, se contarian minutos de una escritura que aun puede
     * revertir.
     */
    public bool $afterCommit = true;

    public function __construct(
        private readonly WorkedTimeMetrics $metrics,
        private readonly EmployeeAttribution $attribution,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(EmployeeClockedOut $event): void
    {
        try {
            $this->metrics->workedMinutes(
                siteId: $event->siteId,
                department: $this->attribution->departmentLabelOf($event->employeeUuid),
                // La duracion de **este** tramo, no el total del dia: sumar el
                // total contaria las horas de la mañana otra vez al cerrar el
                // tramo de la tarde (ADR-024: la pausa son dos tramos).
                minutes: $event->worked->minutes,
            );
        } catch (Throwable $failure) {
            $this->logger->warning('reporting.worked_minutes_metric_failed', [
                'employee_uuid' => $event->employeeUuid,
                'site_id' => $event->siteId,
                'reason' => $failure::class,
            ]);
        }
    }
}
