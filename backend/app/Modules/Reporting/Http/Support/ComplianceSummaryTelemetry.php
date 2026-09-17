<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Http\Middleware\RecordHttpMetrics;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummary;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de la vista de cumplimiento (doc 02 §8.1 y §8.2, RF-PA-06).
 *
 * Mismo sitio y mismo motivo que sus hermanas de este directorio
 * ({@see PeriodReportTelemetry}, `JournalTelemetry`): en el borde, para que el
 * `trace_id` que llega en `traceparent` sea el padre del span y para que la
 * consulta se quede consultando sin un `try/finally` de medicion alrededor.
 *
 * ## Que lleva el log y que no
 *
 * **Ningun nombre y ningun `employee_uuid`** (regla dura 21). Esta consulta
 * devuelve precisamente una lista de personas que han incumplido algo: dejar sus
 * identificadores en un log tecnico de 90 dias que puede acabar en el paquete de
 * diagnostico (ADR-020) seria filtrar la lista mas sensible que produce el
 * producto. De quien eran los hallazgos se responde desde `audit_log`, que tiene
 * control de acceso y retencion propia.
 *
 * **Tampoco el filtro `employee_uuid`**, por lo mismo: se registra que lo hubo,
 * no cual era. Es la misma linea que traza el asiento de divulgacion.
 *
 * Lo que si lleva es **la forma**: rango, alcance, cuantos hallazgos y cuanto
 * tardo. Es lo que hace falta para saber si esta pantalla aguanta en una
 * instalacion real sin preguntarle a nadie.
 *
 * ## Por que aqui no hay un contador propio
 *
 * Las metricas de esta tarea son `compliance_findings_last_week{rule}` y
 * `compliance_employees_affected_last_week` (§8.2), y **no se escriben al abrir la
 * pantalla**: las publica `reporting:compliance-metrics` cada noche sobre la
 * ultima semana completa. Contarlas aqui daria una serie que cambia cada vez que
 * alguien mira. Lo que este endpoint ya tiene es `http_requests_total{route}` y
 * `http_request_duration_seconds{route}` de {@see RecordHttpMetrics}.
 *
 * ## Medir no puede romper una consulta
 *
 * Todo va envuelto en {@see SpanScope}, el andamiaje comun de las telemetrias del
 * backend: un fallo del exportador de trazas no puede dejar sin pantalla a quien
 * tiene que revisar descansos.
 */
final readonly class ComplianceSummaryTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): ComplianceSummary  $read
     */
    public function measure(callable $read): ComplianceSummary
    {
        $span = SpanScope::start('kronoqr.reporting', 'reporting.compliance_summary', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $summary = $read();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $elapsed = microtime(true) - $startedAt;

        $span->end([
            'reporting.range_days' => $summary->range->days(),
            'reporting.findings' => $summary->findingCount(),
            'reporting.scope' => $summary->scopeName(),
        ]);

        $this->logger->info('reporting.compliance_summary_read', [
            'trace_id' => $span->traceId(),
            'from' => $summary->range->isoFrom(),
            'to' => $summary->range->isoTo(),
            'range_days' => $summary->range->days(),
            'findings' => $summary->findingCount(),
            'employees_evaluated' => $summary->totals->employeesEvaluated,
            'scope' => $summary->scopeName(),
            'duration_seconds' => round($elapsed, 3),
        ]);

        return $summary;
    }
}
