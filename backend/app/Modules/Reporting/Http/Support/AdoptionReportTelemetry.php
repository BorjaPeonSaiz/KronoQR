<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Http\Middleware\RecordHttpMetrics;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log del cuadro de impacto y adopcion (doc 02 §8.1, **RF-IN-08**).
 *
 * Mismo sitio y mismo motivo que sus hermanas de este directorio
 * ({@see ComplianceSummaryTelemetry}, {@see PeriodReportTelemetry}): en el borde,
 * para que el `trace_id` que llega en `traceparent` sea el padre del span y para
 * que la consulta se quede consultando sin un `try/finally` de medicion alrededor.
 *
 * ## Que lleva el log, y sobre todo que NO lleva
 *
 * Lleva **la forma de la consulta**: los dos periodos, cuantos dias abarcan,
 * cuantos indicadores salieron con valor y cuanto tardo. Con eso se distingue un
 * cuadro vacio —instalacion recien puesta en marcha— de una consulta mal escrita, y
 * se ve si esta pantalla aguanta en una instalacion real sin preguntarle a nadie.
 *
 * **No lleva ni una cifra del cuadro**, y esa es la correccion de la segunda vuelta
 * (decision 20 de la ficha 3.13). La tentacion era dejar los dos ratios con
 * objetivo —jornadas completas y disponibilidad— para poder contrastar una queja
 * sin pedir una captura. No entran, porque **un ratio sin su denominador es un dato
 * de una persona en cuanto el denominador es pequeño**: `workdays_complete_ratio:
 * 0.0` sobre un dia con una sola jornada dice que alguien concreto se dejo el turno
 * abierto, en un log tecnico de noventa dias que puede acabar en el paquete de
 * diagnostico (ADR-020, regla dura 21). Es exactamente el «recuento pequeño» que
 * este mismo docblock ya excluia.
 *
 * Y no hay suelo de denominador que salve el caso: cualquier cifra a partir de la
 * cual publicar seria una constante arbitraria dentro de un log. De quien eran las
 * horas se responde desde `audit_log`, que tiene control de acceso y retencion
 * propia.
 *
 * ## `format` distingue la consulta de la descarga
 *
 * El mismo span para las dos rutas, con `reporting.format` a `json` o al formato
 * del fichero. Dos nombres de span habrian dado dos series que hay que sumar a mano
 * para responder «¿cuanto se usa el cuadro?».
 *
 * ## Por que aqui no hay un contador propio
 *
 * Las metricas del §8.2 que alimentan el cuadro —`workdays_complete_ratio`,
 * `scans_by_origin_total`, `incident_resolution_seconds`,
 * `employees_without_delivered_credential`— se publican desde las tareas 3.1 y 3.2
 * y se **recalculan** desde los datos (regla dura 7 aplicada a la instrumentacion).
 * Publicar una serie nueva al abrir la pantalla daria un valor que cambia cada vez
 * que alguien mira. Lo que este endpoint ya tiene es `http_requests_total{route}` y
 * `http_request_duration_seconds{route}` de {@see RecordHttpMetrics}.
 *
 * ## Medir no puede romper una consulta
 *
 * Todo va envuelto en {@see SpanScope}, el andamiaje comun de las telemetrias del
 * backend: un fallo del exportador de trazas no puede dejar sin cuadro a quien
 * tiene que decidir si el sistema esta sirviendo.
 */
final readonly class AdoptionReportTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): AdoptionReport  $read
     */
    public function measure(ReportDelivery $format, callable $read): AdoptionReport
    {
        $span = SpanScope::start('kronoqr.reporting', 'reporting.adoption_report', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $report = $read();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $elapsed = microtime(true) - $startedAt;

        $span->end([
            'reporting.format' => $format->value,
            'reporting.range_days' => $report->range->days(),
            'reporting.indicators_with_value' => self::withValue($report),
        ]);

        $this->logger->info('reporting.adoption_report_read', [
            'trace_id' => $span->traceId(),
            'format' => $format->value,
            'from' => $report->range->isoFrom(),
            'to' => $report->range->isoTo(),
            'previous_from' => $report->previousRange->isoFrom(),
            'previous_to' => $report->previousRange->isoTo(),
            'range_days' => $report->range->days(),
            // Cuantos de los doce traen valor. Es el numero que delata un cuadro
            // vacio por una instalacion recien puesta en marcha frente a uno vacio
            // por una consulta mal escrita.
            'indicators_with_value' => self::withValue($report),
            'duration_seconds' => round($elapsed, 3),
        ]);

        return $report;
    }

    private static function withValue(AdoptionReport $report): int
    {
        return \count(array_filter(
            $report->indicators,
            static fn (AdoptionIndicator $indicator): bool => $indicator->current !== null,
        ));
    }
}
