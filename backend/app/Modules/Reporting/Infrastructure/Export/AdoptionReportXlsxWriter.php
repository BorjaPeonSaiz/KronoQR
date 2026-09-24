<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxEngine;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * El cuadro de impacto como hoja de calculo (**RF-IN-08**).
 *
 * ## Dos hojas, y la segunda no es un anexo
 *
 * `Indicadores` lleva las dos tablas —los doce indicadores y el reparto por
 * origen— con la cabecera congelada y los anchos de {@see AdoptionReportLayout}.
 * `Criterios` lleva el periodo, el anterior, el emisor, la huella y las trece
 * lineas de criterios: van en una hoja **visible** y no en las propiedades del
 * documento porque nadie abre las propiedades de un XLSX, y este fichero se
 * archiva y se relee.
 *
 * ## Todas las celdas como texto, y aqui importa mas que en el informe de horas
 *
 * `81:00` interpretado como hora del reloj deja de poder pasar de 24 h, `+2,30 pp`
 * se convierte en basura y `99,94 %` en un numero con formato de porcentaje que la
 * hoja vuelve a redondear por su cuenta. Se construye `StringCell` a mano y no con
 * `Cell::fromValue()`, que deduce el tipo: una celda cuyo texto empiece por `=` se
 * convertiria en `FormulaCell` y llegaria a la hoja de quien la abra como una
 * formula que se ejecuta. Es la misma neutralizacion por construccion que hace el
 * escritor del informe por periodo.
 *
 * ## Aqui no hay presupuesto de memoria que cuidar
 *
 * Doce indicadores y cuatro origenes. Se escribe con el mismo
 * `spatie/simple-excel` que el informe por periodo por coherencia —una sola
 * libreria de XLSX en el producto— y no porque haga falta transmitir.
 */
final readonly class AdoptionReportXlsxWriter
{
    /** Fila en la que empieza el desplazamiento: la 1 es la cabecera y se queda fija. */
    private const int FREEZE_BELOW_HEADER = 2;

    /**
     * Escribe la hoja en el destino y devuelve las filas de indicadores.
     *
     * @param  string  $target  Ruta de fichero o `php://output`. OpenSpout trata los dos igual.
     */
    public function write(AdoptionReport $report, ?string $issuer, string $digest, string $target): int
    {
        $writer = SimpleExcelWriter::streamDownload(
            AdoptionReportLayout::filename($report, 'xlsx'),
            'xlsx',
            static function (WriterInterface $writer) use ($target): void {
                $writer->openToFile($target);
            },
        );

        $rows = self::writeIndicators($writer, $report);
        self::writeCriteria($writer, $report, $issuer, $digest);

        $writer->close();

        return $rows;
    }

    private static function writeIndicators(SimpleExcelWriter $writer, AdoptionReport $report): int
    {
        $writer->nameCurrentSheet(AdoptionReportLayout::text('document.sheet_indicators'));

        self::configureSheet($writer);

        $writer->addRow(self::textRow(AdoptionReportLayout::header()), self::boldStyle());

        $rows = 0;

        foreach ($report->indicators as $indicator) {
            $writer->addRow(self::textRow(AdoptionReportLayout::cells($indicator)));
            $rows++;
        }

        // La segunda tabla, separada por una linea en blanco para que la hoja de
        // calculo reconozca cada una al seleccionarla.
        $writer->addRow(self::textRow([]));
        $writer->addRow(self::textRow([AdoptionReportLayout::text('document.origin_breakdown')]), self::boldStyle());
        $writer->addRow(self::textRow(AdoptionReportLayout::originHeader()), self::boldStyle());

        foreach ($report->originBreakdown as $share) {
            $writer->addRow(self::textRow(AdoptionReportLayout::originCells($share)));
        }

        return $rows;
    }

    private static function writeCriteria(
        SimpleExcelWriter $writer,
        AdoptionReport $report,
        ?string $issuer,
        string $digest,
    ): void {
        $writer->addNewSheetAndMakeItCurrent(AdoptionReportLayout::text('document.sheet_criteria'));

        $writer->addRow(self::textRow([AdoptionReportLayout::text('document.title')]), self::boldStyle());

        foreach (AdoptionReportLayout::metadata($report, $issuer, $digest) as [$label, $value]) {
            $writer->addRow(self::textRow([$label, $value]));
        }

        $writer->addRow(self::textRow([]));
        $writer->addRow(self::textRow([AdoptionReportLayout::text('document.criteria')]), self::boldStyle());

        foreach (AdoptionReportLayout::criteria($report) as $criterion) {
            $writer->addRow(self::textRow([$criterion]));
        }
    }

    /**
     * Cabecera congelada y anchos de columna.
     *
     * Los dos son de la hoja de OpenSpout y no de la libreria envoltorio, asi que
     * hay que bajar hasta el escritor. Si algun dia deja de ser XLSX —no esta
     * previsto—, se salta en silencio en vez de reventar la descarga: una hoja sin
     * congelar sigue siendo una hoja.
     */
    private static function configureSheet(SimpleExcelWriter $writer): void
    {
        $engine = $writer->getWriter();

        if (! $engine instanceof XlsxEngine) {
            return;
        }

        $sheet = $engine->getCurrentSheet();
        $sheet->setSheetView((new SheetView)->setFreezeRow(self::FREEZE_BELOW_HEADER));

        foreach (AdoptionReportLayout::COLUMN_WIDTHS as $index => $width) {
            // OpenSpout indexa las columnas desde 1 en esta API.
            $sheet->setColumnWidth($width, $index + 1);
        }
    }

    /**
     * Una fila con **todas** las celdas forzadas a texto. Ver el docblock de la
     * clase.
     *
     * @param  list<string>  $cells
     */
    private static function textRow(array $cells): Row
    {
        return new Row(array_map(
            static fn (string $value): Cell => $value === ''
                ? new EmptyCell(null, null)
                : new StringCell($value, null),
            $cells,
        ));
    }

    private static function boldStyle(): Style
    {
        return (new Style)->setFontBold();
    }
}
