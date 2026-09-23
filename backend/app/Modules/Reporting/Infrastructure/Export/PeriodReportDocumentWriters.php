<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\ReportExportDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;

/**
 * Elige el escritor segun el formato pedido y sella el documento, **escribiendo
 * a disco** (**RF-IN-06**).
 *
 * ## Es el gemelo de `PeriodReportDocument`, y comparte con el las tres
 * decisiones
 *
 * Aquel sirve la descarga sincrona y este escribe el fichero del diferido, pero
 * las tres cosas que deciden se toman igual en los dos, o el fichero deja de ser
 * el mismo informe segun por donde se pida:
 *
 *   1. **La huella del contenido se calcula una vez**, sobre el informe, antes de
 *      saber en que formato va a salir. Es lo que hace que el CSV y el PDF de
 *      marzo lleven la misma.
 *   2. **El emisor se resuelve una vez**, por el puerto, a partir del `uuid` de
 *      la cuenta que lo pidio.
 *   3. **El formato decide el escritor**, y son los mismos tres objetos.
 *
 * Que sean dos clases y no una es consecuencia de la frontera: aquella construye
 * una `StreamedResponse` y vive en Http; esta escribe un fichero y vive donde el
 * trabajo en cola puede alcanzarla. Lo que **no** se duplica es el contenido: los
 * escritores son los mismos.
 *
 * ## El emisor sale de la fila, no de una sesion
 *
 * El trabajo corre sin nadie delante, asi que el nombre con el que se sella el
 * PDF sale de `requested_by_uuid`, que es quien pidio el informe. Sin cuenta
 * resoluble, `null`, y el pie dice «emisor desconocido» en lugar de dejar el
 * documento sin sellar.
 *
 * ## `ReportDelivery::Json` no llega
 *
 * Ese caso no es un fichero, y ademas `ReportExportKind::allows()` y el `CHECK`
 * de la migracion lo dejan fuera antes. El `match` es exhaustivo sobre el
 * enumerado y lo declara con una excepcion en lugar de con un caso por omision,
 * para que añadir un formato nuevo rompa aqui —donde se ve— y no produzca un CSV
 * en silencio.
 */
final readonly class PeriodReportDocumentWriters implements ReportExportDocumentWriter
{
    public function __construct(
        private PeriodReportCsvWriter $csv,
        private PeriodReportXlsxWriter $xlsx,
        private PeriodReportPdfWriter $pdf,
        private ReportIssuerDirectory $issuers,
    ) {}

    public function fileNameFor(ReportExport $export, PeriodReport $report): string
    {
        // El mismo nombre que la descarga sincrona: periodo y extension, sin
        // ningun dato personal (regla dura 21).
        return PeriodReportLayout::filename($report, $export->format);
    }

    public function write(ReportExport $export, PeriodReport $report, string $path): int
    {
        $digest = PeriodReportDigest::of($report)->toText();
        $issuer = $export->requestedByUuid === null
            ? null
            : $this->issuers->displayNameOf($export->requestedByUuid);

        return match (ReportDelivery::tryFrom($export->format)) {
            ReportDelivery::Csv => $this->csv->write($report, $issuer, $digest, $path),
            ReportDelivery::Xlsx => $this->xlsx->write($report, $issuer, $digest, $path),
            ReportDelivery::Pdf => $this->pdf->write($report, $issuer, $digest, $path),
            default => throw ReportExportWriteFailed::of(
                'el formato pedido no produce un fichero de informe por periodo',
            ),
        };
    }
}
