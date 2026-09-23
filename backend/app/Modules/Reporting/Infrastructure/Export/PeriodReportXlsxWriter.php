<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
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
 * El informe por periodo como hoja de calculo, escrito **a un destino
 * cualquiera** (**RF-IN-04**, RF-IN-06).
 *
 * ## Una sola implementacion para los dos caminos
 *
 * `php://output` en la descarga sincrona y un fichero de
 * `REPORTING_EXPORT_PATH` en la generacion en diferido. Ver el docblock de
 * {@see PeriodReportCsvWriter}: el fichero que llega por el enlace y el que se
 * descarga desde la pantalla tienen que ser el mismo byte a byte.
 *
 * ## `spatie/simple-excel` sobre OpenSpout, en streaming
 *
 * Es la libreria del doc 02 §3.1 y la razon por la que esta elegida —«no carga en
 * memoria un mes de 500 empleados»— se cumple aqui literalmente: OpenSpout
 * escribe cada fila al descriptor segun se le entrega y no construye el documento
 * entero antes de emitirlo. Eso es lo que hace que el mismo escritor valga para
 * un mes de 500 personas y para un trimestre.
 *
 * **Se abre el destino a mano, en vez de dejar que la libreria lo haga.**
 * `openToBrowser()` de OpenSpout llama a `header()` por su cuenta y vacia el
 * buffer de salida: dentro de una `StreamedResponse` de Symfony, cuyas cabeceras
 * ya estan enviadas, eso produce avisos y una descarga con dos juegos de
 * cabeceras. El `writerCallback` de `streamDownload()` existe exactamente para
 * esto, y es tambien el punto por el que aqui entra una ruta de fichero.
 *
 * ## Las horas son **texto**, no numeros
 *
 * Es la exigencia del plan de la tarea 2.9, y la libreria no la garantiza sola:
 * `Cell::fromValue('07:30')` deduce el tipo, y aunque hoy deduzca texto, un
 * cambio de version que decidiera interpretar `07:30` como una hora del reloj
 * convertiria `168:00` en un valor imposible y `-12:30` en un error de celda. Se
 * fuerza con `StringCell` y con el estilo de texto de la hoja: lo que se escribe
 * es lo que se lee.
 *
 * **Nunca un decimal**: ver {@see PeriodReportLayout}. La unica excepcion del
 * producto es la salida a NOMINA (RF-IN-07), que tiene su propio escritor y su
 * propio motivo: ese fichero lo lee un programa, no una persona.
 *
 * ## Dos hojas, y la segunda no es un anexo
 *
 * `Horas` lleva la tabla con la **cabecera congelada** —una hoja de 500 filas sin
 * congelar obliga a subir hasta arriba para saber que columna se esta mirando— y
 * los anchos de columna de `PeriodReportLayout`. `Criterios` lleva el periodo, el
 * emisor, la huella y los criterios de inclusion: van en una hoja visible y no en
 * las propiedades del documento porque nadie abre las propiedades de un XLSX.
 */
final readonly class PeriodReportXlsxWriter
{
    /** Fila en la que empieza el desplazamiento: la 1 es la cabecera y se queda fija. */
    private const int FREEZE_BELOW_HEADER = 2;

    /**
     * Escribe la hoja en el destino y devuelve las filas de datos.
     *
     * @param  string  $target  Ruta de fichero o `php://output`. OpenSpout trata los dos igual.
     */
    public function write(PeriodReport $report, ?string $issuer, string $digest, string $target): int
    {
        $writer = SimpleExcelWriter::streamDownload(
            PeriodReportLayout::filename($report, 'xlsx'),
            'xlsx',
            static function (WriterInterface $writer) use ($target): void {
                $writer->openToFile($target);
            },
        );

        $rows = self::writeHours($writer, $report);
        self::writeCriteria($writer, $report, $issuer, $digest);

        $writer->close();

        return $rows;
    }

    private static function writeHours(SimpleExcelWriter $writer, PeriodReport $report): int
    {
        $writer->nameCurrentSheet(PeriodReportLayout::text('document.sheet_hours'));

        self::configureHoursSheet($writer);

        $writer->addRow(self::textRow(PeriodReportLayout::header()), self::boldStyle());

        $rows = 0;

        foreach ($report->rows as $row) {
            $writer->addRow(self::textRow(PeriodReportLayout::cells($row)));
            $rows++;
        }

        return $rows;
    }

    private static function writeCriteria(SimpleExcelWriter $writer, PeriodReport $report, ?string $issuer, string $digest): void
    {
        $writer->addNewSheetAndMakeItCurrent(PeriodReportLayout::text('document.sheet_criteria'));

        $writer->addRow(self::textRow([PeriodReportLayout::text('document.title')]), self::boldStyle());

        foreach (PeriodReportLayout::metadata($report, $issuer, $digest) as [$label, $value]) {
            $writer->addRow(self::textRow([$label, $value]));
        }

        $writer->addRow(self::textRow([]));
        $writer->addRow(self::textRow([PeriodReportLayout::text('document.criteria')]), self::boldStyle());

        foreach (PeriodReportLayout::criteria($report) as $criterion) {
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
    private static function configureHoursSheet(SimpleExcelWriter $writer): void
    {
        $engine = $writer->getWriter();

        if (! $engine instanceof XlsxEngine) {
            return;
        }

        $sheet = $engine->getCurrentSheet();
        $sheet->setSheetView((new SheetView)->setFreezeRow(self::FREEZE_BELOW_HEADER));

        foreach (PeriodReportLayout::COLUMN_WIDTHS as $index => $width) {
            // OpenSpout indexa las columnas desde 1 en esta API.
            $sheet->setColumnWidth($width, $index + 1);
        }
    }

    /**
     * Una fila con **todas** las celdas forzadas a texto.
     *
     * Ver el docblock de la clase: una duracion `HH:MM` que la hoja interprete
     * como hora del reloj deja de poder pasar de 24 h y de admitir signo, y un
     * codigo de empleado con ceros a la izquierda los pierde en cuanto se
     * interpreta como numero.
     *
     * **Se construye `StringCell` a mano y no con `Cell::fromValue()`**, que
     * deduce el tipo: una celda cuyo texto empiece por `=` —el nombre de un
     * departamento escrito por una persona— se convertiria en `FormulaCell` y
     * llegaria a la hoja de quien lo abra como una formula que se ejecuta. Es la
     * misma neutralizacion que hace el dialecto CSV del producto, resuelta aqui
     * por construccion en lugar de por escapado.
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
