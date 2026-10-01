<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Application\Port\PersonalRecordHolderDirectory;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Support\PersonalRecordPdfTelemetry;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordDigest;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordLayout;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordPdfWriter;
use App\Modules\Shared\Application\Port\Clock;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El historico propio como PDF sellado (`GET /api/v1/me/export?format=pdf`,
 * RF-ID-05, RL-05; PR19 de la verificacion de la 2.1.0).
 *
 * Hermano de {@see PersonalRecordCsv} sobre **el mismo** `WorkDayJournal`: lo
 * que la persona descarga en PDF, en CSV y lo que ve en pantalla salen de una
 * sola consulta y no pueden contradecirse.
 *
 * Aqui no se compone nada, igual que en {@see PeriodReportPdf}: el documento lo
 * compone {@see PersonalRecordPdfWriter} con las plantillas y el sello del
 * informe por periodo. Lo que queda en esta clase es lo que **si** es del borde:
 *
 *   1. **La huella**, una vez y sobre el registro, antes de componer.
 *   2. **El nombre de la persona**, por su puerto, porque es su registro.
 *   3. **El instante de generacion**, por el puerto `Clock` (regla dura 2).
 *   4. **Las cabeceras**, las de {@see StreamedExport}: `no-store`, la huella y
 *      el recuento, iguales que en cualquier otro documento del producto. El
 *      nombre del fichero no lleva ni nombre ni codigo de empleado.
 *
 * Sin Chromium, el puerto del motor lanza `ReportRenderingUnavailable` y el
 * borde responde `503` con la salida escrita dentro: pedirlo en CSV.
 */
final readonly class PersonalRecordPdf
{
    public function __construct(
        private PersonalRecordPdfWriter $writer,
        private PersonalRecordHolderDirectory $holders,
        private Clock $clock,
        private PersonalRecordPdfTelemetry $telemetry,
    ) {}

    public function respond(WorkDayJournal $journal): StreamedResponse
    {
        $digest = PersonalRecordDigest::of($journal)->toText();
        $rows = \count(PersonalRecordLayout::rows($journal));

        $bytes = $this->telemetry->measure(
            $journal,
            $rows,
            fn (): string => $this->writer->render(
                $journal,
                $this->holders->fullNameOf($journal->employeeUuid),
                $digest,
                $this->clock->now(),
            ),
        );

        return (new StreamedExport(
            filename: PersonalRecordLayout::filename($journal, 'pdf'),
            contentType: 'application/pdf',
            digest: $digest,
            rowCount: $rows,
            body: static function () use ($bytes): void {
                echo $bytes;
            },
        ))->toResponse();
    }
}
