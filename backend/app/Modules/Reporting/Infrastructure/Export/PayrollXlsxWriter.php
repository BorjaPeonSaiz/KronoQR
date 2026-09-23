<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\PayrollCell;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\WriterInterface;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * La salida a nomina como hoja de calculo (**RF-IN-07**, RF-IN-04).
 *
 * ## Misma plantilla, mismo fichero
 *
 * Las columnas, su orden y sus rotulos salen de la misma {@see PayrollLayout} que
 * gobierna el CSV, y las celdas de la misma {@see PayrollCell}. Es lo que hace
 * que descargar la nomina de marzo en XLSX y en CSV de exactamente el mismo
 * contenido: si cada escritor resolviera las columnas por su cuenta, una
 * plantilla nueva saldria bien en uno y mal en el otro.
 *
 * Dos ajustes **no aplican aqui y es correcto**:
 *
 * - `PAYROLL_EXPORT_DELIMITER`, porque un XLSX no tiene separador de campos: cada
 *   celda es una celda.
 * - `PAYROLL_EXPORT_ENCODING`, porque el formato es UTF-8 por definicion. Un XLSX
 *   «en latin1» no existe, y transcodificar el texto antes de meterlo produciria
 *   una hoja con los acentos rotos. Quien necesite ISO-8859-1 necesita el CSV, y
 *   es el que su programa de nomina importa.
 *
 * Los otros cuatro —columnas, formato de horas, formato de fechas y fila de
 * cabecera— si gobiernan esta salida.
 *
 * ## Todas las celdas son **texto**, tambien las horas
 *
 * Por lo mismo que en el informe por periodo: `07:30` interpretado como hora del
 * reloj no puede pasar de 24 h ni llevar signo, y un codigo de empleado con ceros
 * a la izquierda los pierde en cuanto la hoja decide que es un numero. Se
 * construye {@see StringCell} a mano y no con `Cell::fromValue()`, que ademas
 * convertiria en formula ejecutable cualquier celda que empiece por `=` — la
 * misma neutralizacion que hace el dialecto CSV, resuelta aqui por construccion.
 *
 * **Tambien las horas decimales van como texto.** Puede parecer contraproducente
 * —quien abra la hoja no podra sumarlas sin convertirlas—, pero el destinatario
 * de este fichero es un importador de nomina, y una celda numerica se serializa
 * con el separador decimal que la hoja decida, no con el que pide la plantilla.
 * Eso convertiria `7,75` en `7.75` a espaldas de quien lo configuro.
 *
 * ## Streaming, con `$path` o con `php://output`
 *
 * `openToFile()` a mano en lugar de `openToBrowser()`: aquel llama a `header()` y
 * vacia el buffer de salida, y dentro de una `StreamedResponse` de Symfony —cuyas
 * cabeceras ya se enviaron— eso produce avisos y dos juegos de cabeceras. El
 * `writerCallback` existe exactamente para esto, y es como lo resolvio ya el
 * informe por periodo.
 */
final readonly class PayrollXlsxWriter
{
    public const string CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Escribe el fichero y devuelve **las filas de datos**, sin la cabecera.
     */
    public function write(PeriodReport $report, PayrollLayout $layout, string $path): int
    {
        $writer = SimpleExcelWriter::streamDownload(
            'payroll.xlsx',
            'xlsx',
            static function (WriterInterface $writer) use ($path): void {
                $writer->openToFile($path);
            },
        );

        if ($layout->hasHeaderRow) {
            $writer->addRow(self::textRow(PayrollHeadings::of($layout)), (new Style)->setFontBold());
        }

        $written = 0;

        foreach ($report->rows as $row) {
            $writer->addRow(self::textRow(PayrollCell::row($row, $layout, $report->timeZone)));
            $written++;
        }

        $writer->close();

        return $written;
    }

    /**
     * Una fila con todas las celdas forzadas a texto. Ver el docblock de la
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
}
