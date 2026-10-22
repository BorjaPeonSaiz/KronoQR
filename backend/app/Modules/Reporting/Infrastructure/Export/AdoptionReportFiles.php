<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Application\Port\AdoptionReportFileWriter;
use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\WrittenAdoptionReport;
use LogicException;

/**
 * El cuadro de impacto escrito en un fichero de la cola de informes
 * (**RF-IN-08**, V5-PA-4).
 *
 * **Los mismos tres escritores** que la descarga sincrona
 * (`AdoptionReportDocument`), con las mismas tres decisiones tomadas igual: la
 * huella se calcula una vez sobre el cuadro, el emisor se resuelve una vez y
 * los criterios se traducen una vez. Lo que cambia es el destino —el fichero
 * de la fila, no el cuerpo de una respuesta— y el embudo del PDF, que aqui es
 * el de la cola: un trabajo puede esperar su hueco de Chromium mucho mas que
 * una persona delante de la pantalla.
 */
final readonly class AdoptionReportFiles implements AdoptionReportFileWriter
{
    public function __construct(
        /** Construido con el embudo de PDF de la cola, no el de la descarga. */
        private AdoptionReportPdfWriter $pdf,
        private ReportIssuerDirectory $issuers,
    ) {}

    public function fileNameFor(AdoptionReport $report, string $format): string
    {
        return AdoptionReportLayout::filename($report, $format);
    }

    public function write(AdoptionReport $report, string $format, string $path, ?string $issuerUuid): WrittenAdoptionReport
    {
        $digest = AdoptionReportDigest::of($report)->toText();
        $issuer = $issuerUuid === null ? null : $this->issuers->displayNameOf($issuerUuid);

        match ($format) {
            'csv' => self::csv($report, $issuer, $digest, $path),
            'xlsx' => (new AdoptionReportXlsxWriter)->write($report, $issuer, $digest, $path),
            'pdf' => self::bytes($path, $this->pdf->render($report, $issuer, $digest)),
            default => throw new LogicException('El cuadro de impacto no sale en '.$format.'.'),
        };

        return new WrittenAdoptionReport(
            rowCount: $report->rowCount(),
            criteria: AdoptionReportLayout::criteria($report),
        );
    }

    private static function csv(AdoptionReport $report, ?string $issuer, string $digest, string $path): int
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw ReportExportWriteFailed::of('no se pudo abrir el fichero del cuadro de impacto');
        }

        try {
            return (new AdoptionReportCsvWriter)->writeTo($handle, $report, $issuer, $digest);
        } finally {
            fclose($handle);
        }
    }

    private static function bytes(string $path, string $bytes): int
    {
        if (@file_put_contents($path, $bytes) === false) {
            throw ReportExportWriteFailed::of('no se pudo escribir el cuadro de impacto');
        }

        return \strlen($bytes);
    }
}
