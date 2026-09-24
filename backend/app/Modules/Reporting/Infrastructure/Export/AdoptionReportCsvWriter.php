<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Support\Facades\App;

/**
 * El cuadro de impacto como CSV, escrito **a un descriptor cualquiera**
 * (**RF-IN-08**).
 *
 * ## Por que no lo escribe `spatie/simple-excel`
 *
 * Por lo mismo que el CSV del informe por periodo, y esta escrito alli con detalle:
 * su escritor llama a `fputcsv` con el fin de linea por omision, `\n`, y no expone
 * ninguna opcion para cambiarlo, asi que con el es imposible entregar el `\r\n` del
 * RFC 4180 que espera Excel en Windows. {@see CsvDialect} existe para que los CSV
 * del producto no puedan divergir en eso.
 *
 * ## El separador y el separador decimal dependen del idioma de la instalacion
 *
 * `;` y coma decimal en español —donde Excel espera punto y coma— y `,` con punto
 * decimal en ingles. Lo decide {@see CsvDialect::delimiterFor()} con `app.locale`,
 * que es configuracion de la instalacion (ADR-017, regla dura 13); el BOM va
 * siempre, porque sin el Excel con configuracion regional española lee el fichero en
 * Windows-1252 y «Jornadas» sale bien pero «Variación» no.
 *
 * ## Dos tablas, una debajo de otra, con una linea en blanco entre ellas
 *
 * Indicadores y reparto por origen. La linea en blanco es lo que permite que una
 * hoja de calculo reconozca cada tabla al seleccionarla, y el bloque de criterios va
 * **antes**, en celdas visibles, por lo mismo que en el informe por periodo: el
 * fichero se abre dos años despues sin nadie al lado que lo explique.
 *
 * **Al contrario que la salida a nomina**, que lleva los criterios en una cabecera
 * HTTP porque una fila de comentario rompe su importacion. Este fichero no lo
 * importa ningun programa.
 */
final readonly class AdoptionReportCsvWriter
{
    /**
     * Escribe el CSV en un descriptor ya abierto y devuelve las filas de
     * indicadores.
     *
     * **No lo cierra**: quien lo abrio decide cuando. En la respuesta sincrona ese
     * descriptor es `php://output` y cerrarlo a destiempo cortaria la transmision.
     *
     * @param  resource  $handle
     */
    public function writeTo($handle, AdoptionReport $report, ?string $issuer, string $digest): int
    {
        $delimiter = CsvDialect::delimiterFor(App::getLocale());

        // La marca de orden de bytes va antes que nada: es lo que le dice a la hoja
        // de calculo que esto es UTF-8.
        CsvDialect::writeByteOrderMark($handle);

        CsvDialect::writeRow($handle, [AdoptionReportLayout::text('document.title')], $delimiter);

        foreach (AdoptionReportLayout::metadata($report, $issuer, $digest) as [$label, $value]) {
            CsvDialect::writeRow($handle, [$label, $value], $delimiter);
        }

        foreach (AdoptionReportLayout::criteria($report) as $index => $criterion) {
            CsvDialect::writeRow($handle, [
                $index === 0 ? AdoptionReportLayout::text('document.criteria') : '',
                $criterion,
            ], $delimiter);
        }

        CsvDialect::writeRow($handle, [], $delimiter);
        CsvDialect::writeRow($handle, AdoptionReportLayout::header(), $delimiter);

        $rows = 0;

        foreach ($report->indicators as $indicator) {
            CsvDialect::writeRow($handle, AdoptionReportLayout::cells($indicator), $delimiter);
            $rows++;
        }

        CsvDialect::writeRow($handle, [], $delimiter);
        CsvDialect::writeRow($handle, [AdoptionReportLayout::text('document.origin_breakdown')], $delimiter);
        CsvDialect::writeRow($handle, AdoptionReportLayout::originHeader(), $delimiter);

        foreach ($report->originBreakdown as $share) {
            CsvDialect::writeRow($handle, AdoptionReportLayout::originCells($share), $delimiter);
        }

        // **Se devuelven los indicadores y no los indicadores mas los origenes**,
        // porque es lo que va en `X-Kronoqr-Report-Rows` y lo que `rowCount()` del
        // cuadro promete. Dos numeros que tienen que decir lo mismo acaban diciendo
        // cosas distintas.
        return $rows;
    }
}
