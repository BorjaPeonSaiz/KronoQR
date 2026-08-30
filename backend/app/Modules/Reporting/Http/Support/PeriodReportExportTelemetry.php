<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Application\Port\ReportExportMetrics;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza, el log y el contador de una descarga de informe (doc 02 §8.1 y
 * §8.2, **RF-IN-04**).
 *
 * ## Es hermana de {@see PeriodReportTelemetry} y no la misma
 *
 * Aquella mide la **consulta** y por eso no tiene contador propio: la metrica de
 * negocio de las horas se incrementa al cerrar un tramo, no al mirar un informe.
 * Esta mide la **descarga**, que si es un suceso contable por si mismo:
 * `report_exports_total{format}` responde a si la exportacion se usa y a cual de
 * los tres formatos, que es lo que decide si la dependencia de Chromium en el
 * servidor de cada cliente sigue estando justificada.
 *
 * Se separan en dos clases en lugar de añadir un parametro opcional a la
 * primera, porque los dos endpoints emiten spans con **nombre distinto** y solo
 * uno incrementa un contador. Un parametro `?ReportDelivery $format = null` en
 * la telemetria de la consulta habria acabado con un `if` decidiendo si esto es
 * una descarga.
 *
 * ## El contador se incrementa **despues** de que el informe exista
 *
 * Si la consulta se sale del presupuesto de RNF-P-05 y responde `422`, no ha
 * habido descarga y no se cuenta. Contar el intento daria una serie que sube
 * cuando alguien pide un rango imposible tres veces seguidas.
 *
 * Lo que **no** se cuenta aqui es el fallo del motor de PDF: eso ocurre despues,
 * al componer el documento, y su sitio es el log de error del adaptador. Cuando
 * la tarea 3.1 publique `/metrics` sera el momento de decidir si merece serie
 * propia.
 *
 * ## Que lleva el log y que no
 *
 * **Ningun nombre y ningun `employee_uuid`** (regla dura 21): la forma del
 * informe —rango, granularidad, agrupacion, filas, formato y cuanto tardo— y
 * nada mas. De quien eran las horas responde `audit_log`, que tiene control de
 * acceso y retencion propia.
 *
 * **La huella del contenido no va en el log**, aunque no seria un dato personal
 * y emparejaria un papel con la linea que lo produjo. El motivo es de coste:
 * calcularla exige recorrer y serializar las filas, y aqui habria que hacerlo una
 * segunda vez —{@see PeriodReportDigest} ya la calcula al sellar el documento—
 * sobre un informe que puede tener quince mil. Viaja en la cabecera
 * `X-Kronoqr-Report-Digest` de la respuesta, que es donde le sirve a quien
 * descarga.
 */
final readonly class PeriodReportExportTelemetry
{
    public function __construct(
        private LoggerInterface $logger,
        private ReportExportMetrics $metrics,
    ) {}

    /**
     * @param  callable(): PeriodReport  $generate
     */
    public function measure(PeriodReportQuery $query, ReportDelivery $format, callable $generate): PeriodReport
    {
        $span = SpanScope::start('kronoqr.reporting', 'reporting.export_period_report', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $report = $generate();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $elapsed = microtime(true) - $startedAt;

        $span->end([
            'reporting.format' => $format->value,
            'reporting.range_days' => $query->range->days(),
            'reporting.granularity' => $query->granularity->value,
            'reporting.group_by' => $query->grouping->value,
            'reporting.rows' => $report->rowCount(),
        ]);

        $this->metrics->exported($format->value);
        $this->log($query, $report, $format, $elapsed, $span);

        return $report;
    }

    private function log(
        PeriodReportQuery $query,
        PeriodReport $report,
        ReportDelivery $format,
        float $elapsed,
        SpanScope $span,
    ): void {
        $this->logger->info('reporting.period_report_exported', [
            'trace_id' => $span->traceId(),
            'format' => $format->value,
            'from' => $query->range->isoFrom(),
            'to' => $query->range->isoTo(),
            'range_days' => $query->range->days(),
            'granularity' => $query->granularity->value,
            'group_by' => $query->grouping->value,
            'rows' => $report->rowCount(),
            'duration_seconds' => round($elapsed, 3),
        ]);
    }
}
