<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Http\Middleware\RecordHttpMetrics;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de una consulta del registro horario (doc 02 §8.1 y §8.2,
 * RF-PA-03).
 *
 * Mismo sitio y mismo motivo que `Attendance\Http\Support\CorrectionTelemetry`
 * —nombrada asi y no como `{@see}` porque Pint resolveria la referencia a un
 * `use` de otro modulo, que la frontera del §1.6 prohibe—: en el borde, para que
 * el `trace_id` que llega en `traceparent` sea el padre del span y para que el
 * caso de uso se quede consultando sin un `try/finally` de medicion alrededor.
 *
 * ## Por que aqui no hay un contador propio
 *
 * El catalogo de metricas del §8.2 no tiene ninguna para las lecturas de
 * pantalla, y este endpoint ya esta contado y cronometrado por
 * `http_requests_total{route,...}` y `http_request_duration_seconds{route,...}`,
 * que emite {@see RecordHttpMetrics} con el **nombre** de la
 * ruta como etiqueta. Inventar aqui una metrica fuera del catalogo la dejaria sin
 * panel, sin alerta y sin nadie que la mire. Lo que si tiene esta consulta, y no
 * es una metrica, es su asiento en `audit_log`: leer las horas de otra persona es
 * una divulgacion de datos personales y RS-05 exige constancia, no un contador.
 *
 * ## Que lleva el log y que no
 *
 * **Ningun nombre** (regla dura 21): `employee_uuid`, el rango consultado y
 * cuantas jornadas se devolvieron. Ni una hora, ni un total, ni el nombre de
 * quien consulta —para eso esta `audit_log`, que tiene control de acceso y
 * retencion propia; esto es un log tecnico de 90 dias que puede acabar en el
 * paquete de diagnostico (ADR-020)—.
 *
 * ## Medir no puede romper una consulta
 *
 * Todo va envuelto. Un fallo del exportador de trazas no puede dejar sin ver el
 * registro horario a quien tiene que corregirlo.
 */
final readonly class JournalTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): WorkDayJournal  $read
     */
    public function measure(string $employeeUuid, callable $read): WorkDayJournal
    {
        $span = $this->startSpan();

        try {
            $journal = $read();
        } catch (Throwable $failure) {
            $this->endSpan($span, null);

            throw $failure;
        }

        $this->endSpan($span, $journal);
        $this->log($employeeUuid, $journal, $span);

        return $journal;
    }

    private function startSpan(): ?SpanInterface
    {
        try {
            return Globals::tracerProvider()
                ->getTracer('kronoqr.reporting')
                ->spanBuilder('reporting.read_employee_workdays')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setParent(Context::getCurrent())
                ->startSpan();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El span no lleva `employee_uuid`: una traza describe **la forma** de la
     * consulta —cuantos dias, cuantas jornadas— y de quien era se responde desde
     * `audit_log`, que es donde ese dato tiene retencion y control de acceso.
     */
    private function endSpan(?SpanInterface $span, ?WorkDayJournal $journal): void
    {
        if (! $span instanceof SpanInterface) {
            return;
        }

        try {
            if ($journal instanceof WorkDayJournal) {
                $span->setAttribute('reporting.range_days', $journal->range->days());
                $span->setAttribute('reporting.work_days', $journal->dayCount());
            }

            $span->end();
        } catch (Throwable) {
            // Ver el docblock: medir no puede romper una consulta.
        }
    }

    private function log(string $employeeUuid, WorkDayJournal $journal, ?SpanInterface $span): void
    {
        $this->logger->info('reporting.employee_workdays_read', [
            'trace_id' => $this->traceIdOf($span),
            'employee_uuid' => $employeeUuid,
            'from' => $journal->range->isoFrom(),
            'to' => $journal->range->isoTo(),
            'work_days' => $journal->dayCount(),
        ]);
    }

    private function traceIdOf(?SpanInterface $span): ?string
    {
        if (! $span instanceof SpanInterface) {
            return null;
        }

        $traceId = $span->getContext()->getTraceId();

        // Un `trace_id` a ceros es el de un span inerte: escribirlo seria peor
        // que no escribir nada, porque parece un identificador.
        return trim($traceId, '0') === '' ? null : $traceId;
    }
}
