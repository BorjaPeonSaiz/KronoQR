<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportCsvWriter;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportLayout;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;

/**
 * El informe por periodo como CSV **transmitido** (**RF-IN-04**).
 *
 * ## Aqui ya no se escribe nada: solo se envuelve
 *
 * El contenido lo escribe {@see PeriodReportCsvWriter}, que vive en
 * `Infrastructure/Export` y es **el mismo** que usa la generacion en diferido de
 * RF-IN-06 (tarea 3.9). Con dos implementaciones, el fichero que llega por el
 * enlace de descarga y el que se descarga desde la pantalla podrian discrepar, y
 * el que se creeria seria el equivocado — el mismo argumento por el que esta
 * descarga reutiliza la consulta del panel en lugar de tener una SQL propia.
 *
 * Lo que queda en esta clase es lo que **si** es de la capa Http: el nombre del
 * fichero, el tipo de contenido y las cabeceras de {@see StreamedExport}.
 *
 * ## Streaming, y aqui se puede
 *
 * Al contrario que la exportacion legal, que escribe a un temporal para poder
 * auditar **antes** de entregar nada. Aqui el asiento de `audit_log` ya lo
 * escribio el caso de uso —antes de devolver el informe, y por tanto antes de que
 * empiece esta respuesta—, asi que no hay nada que garantizar despues y no hace
 * falta dejar en disco un fichero con las horas nominales de la plantilla.
 */
final readonly class PeriodReportCsv
{
    public static function respond(PeriodReport $report, ?string $issuer, string $digest): StreamedExport
    {
        $writer = new PeriodReportCsvWriter;

        return new StreamedExport(
            filename: PeriodReportLayout::filename($report, 'csv'),
            contentType: CsvDialect::CONTENT_TYPE,
            digest: $digest,
            rowCount: $report->rowCount(),
            body: static function () use ($writer, $report, $issuer, $digest): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                $writer->writeTo($handle, $report, $issuer, $digest);

                fclose($handle);
            },
        );
    }

    /** No se instancia: es la forma de una respuesta, no un colaborador. */
    private function __construct() {}
}
