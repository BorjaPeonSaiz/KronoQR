<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Support;

use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Psr\Log\LoggerInterface;

/**
 * La linea de log de la composicion del PDF del registro propio (PR19).
 *
 * La consulta ya la mide su caso de uso (`reporting.read_employee_work_days`).
 * Lo que este añade es lo que solo ocurre con el PDF: arrancar Chromium, que es
 * la parte lenta y la que puede fallar. Sin su propio span, una descarga de
 * cuatro segundos se veria en la traza como una consulta lenta. El span lo abre
 * {@see UseCaseInstrumentation}, la medida comun (V1-RV-03): renderizar no es un
 * caso de uso de `Application`, pero es un acto con su duracion y su desenlace.
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
        $startedAt = microtime(true);

        $result = UseCaseInstrumentation::run(
            'reporting.render_personal_record_pdf',
            $render,
            static fn (): array => [
                'reporting.range_days' => $journal->range->days(),
                'reporting.rows' => $rows,
            ],
        );

        $elapsed = microtime(true) - $startedAt;

        $this->logger->info('reporting.personal_record_pdf_rendered', [
            'trace_id' => SpanScope::currentTraceId(),
            'employee_uuid' => $journal->employeeUuid,
            'from' => $journal->range->isoFrom(),
            'to' => $journal->range->isoTo(),
            'rows' => $rows,
            'duration_seconds' => round($elapsed, 3),
        ]);

        return $result;
    }
}
