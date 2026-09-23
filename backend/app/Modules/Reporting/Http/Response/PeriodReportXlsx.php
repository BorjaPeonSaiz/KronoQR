<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportLayout;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportXlsxWriter;

/**
 * El informe por periodo como hoja de calculo **transmitida** (**RF-IN-04**).
 *
 * ## Aqui ya no se escribe nada: solo se envuelve
 *
 * El contenido lo escribe {@see PeriodReportXlsxWriter}, que vive en
 * `Infrastructure/Export` y es **el mismo** que usa la generacion en diferido de
 * RF-IN-06 (tarea 3.9). El destino es lo unico que cambia: aqui `php://output` y
 * alli un fichero de `REPORTING_EXPORT_PATH`. Ver el docblock de
 * {@see PeriodReportCsv}.
 *
 * Lo que queda en esta clase es lo que **si** es de la capa Http: el nombre del
 * fichero, el tipo de contenido y las cabeceras de {@see StreamedExport}.
 */
final readonly class PeriodReportXlsx
{
    public const string CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function respond(PeriodReport $report, ?string $issuer, string $digest): StreamedExport
    {
        $writer = new PeriodReportXlsxWriter;

        return new StreamedExport(
            filename: PeriodReportLayout::filename($report, 'xlsx'),
            contentType: self::CONTENT_TYPE,
            digest: $digest,
            rowCount: $report->rowCount(),
            body: static function () use ($writer, $report, $issuer, $digest): void {
                $writer->write($report, $issuer, $digest, 'php://output');
            },
        );
    }

    /** No se instancia: es la forma de una respuesta, no un colaborador. */
    private function __construct() {}
}
