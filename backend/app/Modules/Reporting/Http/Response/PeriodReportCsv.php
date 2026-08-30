<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Http\Support\PeriodReportLayout;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Support\Facades\App;

/**
 * El informe por periodo como CSV (**RF-IN-04**).
 *
 * ## Por que no lo escribe `spatie/simple-excel`
 *
 * El plan de la tarea nombra esa libreria para los tres formatos, y para el XLSX
 * es la que se usa. Para el CSV **no se puede**, y el motivo esta escrito desde
 * la tarea 1.17 en el escritor de la exportacion legal —nombrado en prosa porque
 * `Reporting` no puede importar `Compliance` (doc 02 §1.6)—: su escritor de CSV
 * llama a `fputcsv` con el fin de linea por omision, `\n`, y no expone ninguna
 * opcion para cambiarlo. Con el es imposible entregar el `\r\n` del RFC 4180 que
 * espera Excel en Windows.
 *
 * Escribirlo con la libreria habria dado un tercer CSV del producto con un
 * formato distinto de los otros dos. Eso ya paso una vez —el historico del
 * portal salia con `\r\n` y el de la Inspeccion con `\n`, y nadie se entero— y
 * es exactamente lo que {@see CsvDialect} existe para impedir. Lo que
 * justificaba elegir la libreria, «no carga en memoria un mes de 500 empleados»,
 * se conserva intacto: aqui se escribe fila a fila sobre `php://output`.
 *
 * ## El separador depende del idioma de la instalacion
 *
 * `;` en español —donde la coma es el separador decimal y Excel espera punto y
 * coma— y `,` en ingles, donde `;` metaria todas las columnas en la primera
 * celda. Lo decide {@see CsvDialect::delimiterFor()} con `app.locale`, que es
 * configuracion de la instalacion (ADR-017, regla dura 13). El BOM va siempre:
 * sin el, Excel con configuracion regional española lee el fichero en
 * Windows-1252 y «Duración» sale «DuraciÃ³n».
 *
 * ## Los criterios van en un bloque **visible**, antes de la tabla
 *
 * No en un comentario, no en las propiedades del fichero: en celdas, seguidos de
 * una linea en blanco. La linea en blanco es lo que permite que una hoja de
 * calculo reconozca la tabla al seleccionarla, y el bloque es lo que hace que el
 * fichero se explique solo cuando alguien lo abra dos años despues. Es la misma
 * disposicion que los otros dos CSV del producto.
 *
 * ## Streaming, y aqui se puede
 *
 * Al contrario que la exportacion legal, que escribe a un temporal para poder
 * auditar **antes** de entregar nada. Aqui el asiento de `audit_log` ya lo
 * escribio el caso de uso —antes de devolver el informe, y por tanto antes de
 * que empiece esta respuesta—, asi que no hay nada que garantizar despues y no
 * hace falta dejar en disco un fichero con las horas nominales de la plantilla.
 */
final readonly class PeriodReportCsv
{
    public static function respond(PeriodReport $report, ?string $issuer, string $digest): StreamedExport
    {
        $delimiter = CsvDialect::delimiterFor(App::getLocale());

        return new StreamedExport(
            filename: PeriodReportLayout::filename($report, 'csv'),
            contentType: CsvDialect::CONTENT_TYPE,
            digest: $digest,
            rowCount: $report->rowCount(),
            body: static function () use ($report, $issuer, $digest, $delimiter): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                // La marca de orden de bytes va antes que nada: es lo que le dice
                // a la hoja de calculo que esto es UTF-8.
                CsvDialect::writeByteOrderMark($handle);

                self::writeHeader($handle, $report, $issuer, $digest, $delimiter);

                foreach ($report->rows as $row) {
                    CsvDialect::writeRow($handle, PeriodReportLayout::cells($row), $delimiter);
                }

                fclose($handle);
            },
        );
    }

    /**
     * Metadatos, criterios, linea en blanco y rotulos.
     *
     * @param  resource  $handle
     */
    private static function writeHeader($handle, PeriodReport $report, ?string $issuer, string $digest, string $delimiter): void
    {
        CsvDialect::writeRow($handle, [PeriodReportLayout::text('document.title')], $delimiter);

        foreach (PeriodReportLayout::metadata($report, $issuer, $digest) as [$label, $value]) {
            CsvDialect::writeRow($handle, [$label, $value], $delimiter);
        }

        foreach (PeriodReportLayout::criteria($report) as $index => $criterion) {
            CsvDialect::writeRow($handle, [
                $index === 0 ? PeriodReportLayout::text('document.criteria') : '',
                $criterion,
            ], $delimiter);
        }

        CsvDialect::writeRow($handle, [], $delimiter);
        CsvDialect::writeRow($handle, PeriodReportLayout::header(), $delimiter);
    }

    /** No se instancia: es la forma de un fichero, no un colaborador. */
    private function __construct() {}
}
