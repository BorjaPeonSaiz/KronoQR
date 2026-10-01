<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Span y linea de log de la composicion del PDF del registro propio (PR19).
 *
 * La consulta ya la mide {@see JournalTelemetry} (`reporting.read_employee_workdays`).
 * Lo que este añade es lo que solo ocurre con el PDF: arrancar Chromium, que es
 * la parte lenta y la que puede fallar. Sin su propio span, una descarga de
 * cuatro segundos se veria en la traza como una consulta lenta.
 *
 * **Sin metrica propia, y es deliberado.** `report_exports_total{format}` cuenta
 * los informes de gestion; meter aqui el registro personal mezclaria dos
 * poblaciones en la misma serie. Si el fabricante quiere contar descargas del
 * portal, es una serie nueva con su regla, no un `format` mas.
 *
 * El log lleva `employee_uuid` y nunca el nombre (regla dura 21), aunque el
 * documento si lo imprima: el log viaja al fabricante y el papel no.
 */
final readonly class PersonalRecordPdfTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @template T
     *
     * @param  callable(): T  $render
     * @return T
     */
    public function measure(WorkDayJournal $journal, int $rows, callable $render): mixed
    {
        $span = SpanScope::start('kronoqr.reporting', 'reporting.render_personal_record_pdf', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $result = $render();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $elapsed = microtime(true) - $startedAt;

        $span->end([
            'reporting.range_days' => $journal->range->days(),
            'reporting.rows' => $rows,
        ]);

        $this->logger->info('reporting.personal_record_pdf_rendered', [
            'trace_id' => $span->traceId(),
            'employee_uuid' => $journal->employeeUuid,
            'from' => $journal->range->isoFrom(),
            'to' => $journal->range->isoTo(),
            'rows' => $rows,
            'duration_seconds' => round($elapsed, 3),
        ]);

        return $result;
    }
}
