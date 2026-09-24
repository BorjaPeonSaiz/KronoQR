<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Application\Port\ReportIssuerDirectory;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Infrastructure\Export\AdoptionReportCsvWriter;
use App\Modules\Reporting\Infrastructure\Export\AdoptionReportDigest;
use App\Modules\Reporting\Infrastructure\Export\AdoptionReportLayout;
use App\Modules\Reporting\Infrastructure\Export\AdoptionReportPdfWriter;
use App\Modules\Reporting\Infrastructure\Export\AdoptionReportXlsxWriter;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use LogicException;
use RuntimeException;

/**
 * Compone el cuadro de impacto en el formato pedido y lo sella (**RF-IN-08**).
 *
 * ## Por que la eleccion del formato no esta en el controlador
 *
 * Porque no es una linea: son tres decisiones que los tres formatos comparten y que
 * tienen que tomarse **igual** en los tres, o el fichero deja de ser el mismo cuadro
 * segun como se descargue. Mismo reparto que {@see PeriodReportDocument}.
 *
 *   1. **La huella se calcula una vez**, sobre el cuadro y antes de saber en que
 *      formato va a salir. Es lo que hace que el CSV y el PDF de marzo lleven la
 *      misma. Calcularla dentro de cada escritor invitaria a que uno de los tres la
 *      calculara sobre otra cosa.
 *   2. **El emisor se resuelve una vez**, por el puerto y con el `uuid` que ya trae
 *      la peticion autenticada. Quien descarga no puede declarar quien es.
 *   3. **Los criterios se traducen una vez** y viajan con el fichero, para que la
 *      cabecera y el contenido no puedan decir cosas distintas.
 *
 * ## Devuelve bytes, no una respuesta
 *
 * {@see AdoptionReportFile}, que se compone entero en memoria. Ahi esta el motivo
 * completo; en una frase: **el asiento de `audit_log` se escribe antes de que salga
 * un byte** (regla dura 6) y necesita el tamaño del fichero, que no se sabe mientras
 * se esta escribiendo. El controlador compone, audita y **despues** responde.
 *
 * ## `ReportDelivery::Json` y `::Mail` no llegan
 *
 * El JSON lo sirve `AdoptionReportResource` desde el otro endpoint; `Mail` es el
 * cuerpo del resumen semanal (RF-PR-05) y no tiene nada que ver con esto. El `match`
 * es exhaustivo sobre el enumerado y lo declara con una excepcion en lugar de con un
 * caso por omision, para que añadir un formato nuevo rompa aqui —donde se ve— y no
 * produzca un CSV en silencio.
 */
final readonly class AdoptionReportDocument
{
    public function __construct(
        private AdoptionReportPdfWriter $pdf,
        private ReportIssuerDirectory $issuers,
    ) {}

    public function compose(AdoptionReport $report, ReportDelivery $format, string $actorUuid): AdoptionReportFile
    {
        $digest = AdoptionReportDigest::of($report)->toText();
        $issuer = $this->issuers->displayNameOf($actorUuid);

        [$contentType, $extension, $bytes] = match ($format) {
            ReportDelivery::Csv => [
                CsvDialect::CONTENT_TYPE,
                'csv',
                self::csv($report, $issuer, $digest),
            ],
            ReportDelivery::Xlsx => [
                PeriodReportXlsx::CONTENT_TYPE,
                'xlsx',
                self::xlsx($report, $issuer, $digest),
            ],
            ReportDelivery::Pdf => [
                'application/pdf',
                'pdf',
                $this->pdf->render($report, $issuer, $digest),
            ],
            ReportDelivery::Json => throw new LogicException(
                'El cuadro de impacto en JSON lo sirve GET /api/v1/reports/adoption, no la descarga.',
            ),
            ReportDelivery::Mail => throw new LogicException(
                'El cuadro de impacto no se envia por correo: eso es el resumen semanal (RF-PR-05).',
            ),
        };

        return new AdoptionReportFile(
            filename: AdoptionReportLayout::filename($report, $extension),
            contentType: $contentType,
            bytes: $bytes,
            digest: $digest,
            rowCount: $report->rowCount(),
            criteria: AdoptionReportLayout::criteria($report),
        );
    }

    /**
     * El CSV, escrito a memoria.
     *
     * `php://temp` y no `php://memory`: el primero se derrama a disco si el
     * contenido creciera, y aunque aqui no pueda —doce filas—, es el que no impone
     * un techo que nadie recordaria el dia que el cuadro tenga treinta indicadores.
     * El escritor es **el mismo** que serviria a un fichero, porque lo unico que
     * cambia es el descriptor.
     */
    private static function csv(AdoptionReport $report, ?string $issuer, string $digest): string
    {
        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el buffer para escribir el cuadro de impacto en CSV.');
        }

        try {
            (new AdoptionReportCsvWriter)->writeTo($handle, $report, $issuer, $digest);

            rewind($handle);
            $bytes = stream_get_contents($handle);

            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer el cuadro de impacto escrito en CSV.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /**
     * El XLSX, escrito a un fichero temporal.
     *
     * OpenSpout necesita un destino con posicionamiento —un ZIP se escribe saltando
     * hacia atras para cerrar sus entradas— y `php://temp` no siempre lo permite a
     * traves de la capa de flujos de la libreria. Un temporal del sistema si, y se
     * borra en el `finally` pase lo que pase: un fichero con el cuadro de impacto no
     * se queda en el disco del servidor aunque no lleve datos personales.
     */
    private static function xlsx(AdoptionReport $report, ?string $issuer, string $digest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kronoqr-adoption-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el fichero temporal del cuadro de impacto en XLSX.');
        }

        try {
            (new AdoptionReportXlsxWriter)->write($report, $issuer, $digest, $path);

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer el cuadro de impacto escrito en XLSX.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
