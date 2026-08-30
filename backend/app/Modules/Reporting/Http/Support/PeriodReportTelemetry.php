<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Http\Middleware\RecordHttpMetrics;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de la generacion de un informe por periodo (doc 02 §8.1 y
 * §8.2, RF-IN-01).
 *
 * Mismo sitio y mismo motivo que `JournalTelemetry`, su hermana de este
 * directorio: en el borde, para que el `trace_id` que llega en `traceparent` sea
 * el padre del span y para que la consulta se quede consultando sin un
 * `try/finally` de medicion alrededor.
 *
 * ## Por que aqui no hay un contador propio
 *
 * La metrica de negocio de esta tarea es `worked_minutes_total{site,department}`
 * (§8.2) y **no se incrementa al leer el informe**: se incrementa al **cerrar un
 * tramo**, que es cuando el hecho ocurre. Contarla aqui daria una serie que sube
 * cada vez que alguien abre la pantalla y que cuenta las mismas horas tantas
 * veces como se consulten — un contador de Prometheus solo puede crecer, asi que
 * no habria forma de corregirlo. El listener que la escribe es
 * `Reporting\Infrastructure\Listener\RecordWorkedMinutes`.
 *
 * Lo que si tiene este endpoint, y ya lo tiene, es `http_requests_total{route}` y
 * `http_request_duration_seconds{route}` que emite {@see RecordHttpMetrics} con
 * el **nombre** de la ruta. Inventar aqui una metrica fuera del catalogo del §8.2
 * la dejaria sin panel, sin alerta y sin nadie que la mire.
 *
 * ## Que lleva el log y que no
 *
 * **Ningun nombre y ningun `employee_uuid`** (regla dura 21). Un informe por
 * empleado de la plantilla entera dejaria quinientos identificadores en un log
 * tecnico de 90 dias que puede acabar en el paquete de diagnostico (ADR-020). De
 * quien eran las horas se responde desde `audit_log`, que tiene control de acceso
 * y retencion propia, y ahi si estan.
 *
 * Lo que si lleva es **la forma** del informe: rango, granularidad, agrupacion,
 * filas y cuanto tardo. Es lo que hace falta para saber si RNF-P-05 se esta
 * cumpliendo en una instalacion real sin preguntarle a nadie.
 *
 * ## Medir no puede romper una consulta
 *
 * Todo va envuelto en {@see SpanScope}, el andamiaje comun de las telemetrias del
 * backend. Un fallo del exportador de trazas no puede dejar sin informe a quien
 * tiene que cerrar una nomina.
 */
final readonly class PeriodReportTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): PeriodReport  $generate
     */
    public function measure(PeriodReportQuery $query, callable $generate): PeriodReport
    {
        $span = SpanScope::start('kronoqr.reporting', 'reporting.generate_period_report', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $report = $generate();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $elapsed = microtime(true) - $startedAt;

        $span->end([
            'reporting.range_days' => $query->range->days(),
            'reporting.granularity' => $query->granularity->value,
            'reporting.group_by' => $query->grouping->value,
            'reporting.rows' => $report->rowCount(),
        ]);

        $this->log($query, $report, $elapsed, $span);

        return $report;
    }

    private function log(PeriodReportQuery $query, PeriodReport $report, float $elapsed, SpanScope $span): void
    {
        $this->logger->info('reporting.period_report_generated', [
            'trace_id' => $span->traceId(),
            'from' => $query->range->isoFrom(),
            'to' => $query->range->isoTo(),
            'range_days' => $query->range->days(),
            'granularity' => $query->granularity->value,
            'group_by' => $query->grouping->value,
            'rows' => $report->rowCount(),
            // El presupuesto de RNF-P-05 es de 5 s. Sin esta linea, saber si se
            // cumple en la instalacion de un cliente exigiria reproducirlo.
            'duration_seconds' => round($elapsed, 3),
            'days_without_contract' => $report->contractCoverage->daysWithoutContract,
        ]);
    }
}
