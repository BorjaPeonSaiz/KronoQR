<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportLayout;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportPdfWriter;

/**
 * El informe por periodo como PDF sellado **transmitido** (**RF-IN-04**).
 *
 * ## Aqui ya no se compone nada: solo se envuelve
 *
 * El documento lo compone {@see PeriodReportPdfWriter}, que vive en
 * `Infrastructure/Export` —donde puede alcanzar el motor de composicion y los
 * puertos de marca— y es **el mismo** que usa la generacion en diferido de
 * RF-IN-06. Ver el docblock de {@see PeriodReportCsv}.
 *
 * Lo que queda en esta clase es lo que **si** es de la capa Http: el nombre del
 * fichero, el tipo de contenido y las cabeceras de {@see StreamedExport}.
 *
 * ## El PDF no se emite por partes, y se sirve igual que los otros dos
 *
 * El motor lo compone entero antes de devolver los bytes, asi que aqui no hay
 * streaming que valga. Se envuelve en la misma `StreamedExport` que el CSV y el
 * XLSX para que las cabeceras —`no-store`, la huella y el recuento— no puedan
 * divergir entre formatos.
 */
final readonly class PeriodReportPdf
{
    public function __construct(private PeriodReportPdfWriter $writer) {}

    public function respond(PeriodReport $report, ?string $issuer, string $digest): StreamedExport
    {
        $bytes = $this->writer->render($report, $issuer, $digest);

        return new StreamedExport(
            filename: PeriodReportLayout::filename($report, 'pdf'),
            contentType: 'application/pdf',
            digest: $digest,
            rowCount: $report->rowCount(),
            body: static function () use ($bytes): void {
                echo $bytes;
            },
        );
    }
}
