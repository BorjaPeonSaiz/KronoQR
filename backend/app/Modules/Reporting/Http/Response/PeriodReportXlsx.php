<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Response;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Http\Support\PeriodReportLayout;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * El informe por periodo como hoja de calculo (**RF-IN-04**).
 *
 * ## `spatie/simple-excel` sobre OpenSpout, en streaming
 *
 * Es la libreria del doc 02 §3.1 y la razon por la que esta elegida —«no carga
 * en memoria un mes de 500 empleados»— se cumple aqui literalmente: OpenSpout
 * escribe cada fila al descriptor segun se le entrega y no construye el
 * documento entero antes de emitirlo.
 *
 * **Se abre sobre `php://output` a mano, en vez de dejar que la libreria lo
 * haga.** `openToBrowser()` de OpenSpout llama a `header()` por su cuenta y
 * vacia el buffer de salida: dentro de una `StreamedResponse` de Symfony, cuyas
 * cabeceras ya estan enviadas, eso produce avisos y una descarga con dos juegos
 * de cabeceras. El `writerCallback` de `streamDownload()` existe exactamente para
 * esto.
 *
 * ## Las horas son **texto**, no numeros
 *
 * Es la exigencia del plan de la tarea, y la libreria no la garantiza sola:
 * `Cell::fromValue('07:30')` deduce el tipo, y aunque hoy deduzca texto, un
 * cambio de version que decidiera interpretar `07:30` como una hora del reloj
 * convertiria `168:00` en un valor imposible y `-12:30` en un error de celda. Se
 * fuerza con {@see Cell::fromValue()} sobre cadenas y con el estilo de texto de
 * la hoja: lo que se escribe es lo que se lee.
 *
 * **Nunca un decimal**: ver {@see PeriodReportLayout}, que ademas es la razon por
 * la que este fichero no tiene ninguna columna de minutos.
 *
 * ## Dos hojas, y la segunda no es un anexo
 *
 * `Horas` lleva la tabla con la **cabecera congelada** —una hoja de 500 filas sin
 * congelar obliga a subir hasta arriba para saber que columna se esta mirando— y
 * los anchos de columna de `PeriodReportLayout`. `Criterios` lleva el periodo, el
 * emisor, la huella y los criterios de inclusion: van en una hoja visible y no en
 * las propiedades del documento porque nadie abre las propiedades de un XLSX.
 */
final readonly class PeriodReportXlsx
{
    public const string CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Fila en la que empieza el desplazamiento: la 1 es la cabecera y se queda fija. */
    private const int FREEZE_BELOW_HEADER = 2;

    public static function respond(PeriodReport $report, ?string $issuer, string $digest): StreamedExport
    {
        return new StreamedExport(
            filename: PeriodReportLayout::filename($report, 'xlsx'),
            contentType: self::CONTENT_TYPE,
            digest: $digest,
            rowCount: $report->rowCount(),
            body: static function () use ($report, $issuer, $digest): void {
                $writer = SimpleExcelWriter::streamDownload(
                    PeriodReportLayout::filename($report, 'xlsx'),
                    'xlsx',
                    static function (WriterInterface $writer): void {
                        $writer->openToFile('php://output');
                    },
                );

                self::writeHours($writer, $report);
                self::writeCriteria($writer, $report, $issuer, $digest);

                $writer->close();
            },
        );
    }

    private static function writeHours(SimpleExcelWriter $writer, PeriodReport $report): void
    {
        $writer->nameCurrentSheet(PeriodReportLayout::text('document.sheet_hours'));

        self::configureHoursSheet($writer);

        $writer->addRow(self::textRow(PeriodReportLayout::header()), self::boldStyle());

        foreach ($report->rows as $row) {
            $writer->addRow(self::textRow(PeriodReportLayout::cells($row)));
        }
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
     * previsto—, se salta en silencio en vez de reventar la descarga: una hoja
     * sin congelar sigue siendo una hoja.
     */
    private static function configureHoursSheet(SimpleExcelWriter $writer): void
    {
        $engine = $writer->getWriter();

        if (! $engine instanceof XlsxWriter) {
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

    /** No se instancia: es la forma de un fichero, no un colaborador. */
    private function __construct() {}
}
