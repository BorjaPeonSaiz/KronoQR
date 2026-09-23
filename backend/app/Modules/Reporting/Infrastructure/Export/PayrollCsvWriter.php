<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Export;

use App\Modules\Reporting\Domain\ValueObject\PayrollCell;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Shared\Domain\ValueObject\PayrollEncoding;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use RuntimeException;

/**
 * La salida a nomina como CSV, **escrita segun la plantilla del cliente**
 * (**RF-IN-07**, ADR-017, regla dura 13).
 *
 * ## Que decide la plantilla y que sigue decidiendo el producto
 *
 * De la plantilla salen las columnas, su orden, sus rotulos, el separador, el
 * formato de las horas y de las fechas, la codificacion y si hay fila de
 * cabecera. Del producto siguen saliendo el entrecomillado del RFC 4180, el fin
 * de linea `\r\n` y la neutralizacion de formulas, que los pone
 * {@see CsvDialect}: son las tres decisiones que hacen que el fichero se pueda
 * volver a leer y que un apellido que empiece por `=` no se ejecute en la hoja de
 * calculo de quien lo reciba. Ninguna de las tres es negociable por configuracion,
 * y por eso no hay ninguna clave que las toque.
 *
 * ## **Sin bloque de criterios dentro del fichero**
 *
 * Es la diferencia deliberada con el informe por periodo, donde los criterios van
 * en celdas visibles antes de la tabla porque lo abre una persona. Aqui lo
 * importa un programa, y una linea de comentario antes de la cabecera rompe la
 * importacion: el importador la lee como una fila de datos con una sola columna y
 * aborta, o peor, da de alta un empleado llamado «Criterios de este informe».
 *
 * Los criterios **no se pierden**: viajan en la cabecera
 * `X-Kronoqr-Export-Criteria` de la descarga sincrona, en la columna `criteria`
 * de la exportacion en diferido y en la pantalla del panel.
 *
 * ## `latin1` nunca falla
 *
 * Se transcodifica con `iconv` y translitera —«á» cabe en ISO-8859-1 y se
 * conserva; «€» o un ideograma no caben y salen como `?`—. Un fichero de nomina a
 * medias es peor que uno con un caracter aproximado: el primero deja a alguien sin
 * cobrar y el segundo se corrige mirando la ficha. Por eso hay tres intentos en
 * cascada y ninguno lanza.
 *
 * ## Streaming, con `$path` o con `php://output`
 *
 * La misma implementacion sirve a la descarga sincrona y al trabajo en cola,
 * porque `$path` admite `php://output`. Una segunda implementacion para el
 * diferido acabaria entregando un fichero distinto del que se descarga en el
 * acto — y el que se creeria seria el equivocado.
 */
final readonly class PayrollCsvWriter
{
    public const string CONTENT_TYPE_UTF8 = 'text/csv; charset=utf-8';

    public const string CONTENT_TYPE_LATIN1 = 'text/csv; charset=iso-8859-1';

    /**
     * Escribe el fichero y devuelve **las filas de datos**, sin contar la
     * cabecera.
     *
     * El recuento es lo que viaja a `row_count` de la exportacion y a la cabecera
     * `X-Kronoqr-Export-Rows`: sirve para comprobar que la descarga llego entera,
     * y para eso tiene que contar lo mismo que cuenta el informe.
     */
    public function write(PeriodReport $report, PayrollLayout $layout, string $path): int
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            // Un fichero de nomina que no se puede abrir es un fallo ruidoso a
            // proposito: devolver cero filas lo haria pasar por «no hay nadie».
            throw new RuntimeException('No se ha podido abrir el destino del fichero de nomina.');
        }

        try {
            if ($layout->encoding->hasByteOrderMark()) {
                fwrite($handle, CsvDialect::BYTE_ORDER_MARK);
            }

            $delimiter = $layout->delimiter->character();

            if ($layout->hasHeaderRow) {
                $this->writeRow($handle, PayrollHeadings::of($layout), $delimiter, $layout->encoding);
            }

            $written = 0;

            foreach ($report->rows as $row) {
                $this->writeRow(
                    $handle,
                    PayrollCell::row($row, $layout, $report->timeZone),
                    $delimiter,
                    $layout->encoding,
                );

                $written++;
            }

            return $written;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Una fila, con la codificacion pedida.
     *
     * **Camino rapido en UTF-8**: se escribe directamente sobre el destino, sin
     * pasar por ningun intermedio. Solo `latin1` necesita el rodeo, porque
     * `fputcsv` no sabe transcodificar y hacerlo celda a celda antes de
     * entrecomillar romperia el escapado del RFC 4180 en cuanto un rotulo llevara
     * una comilla.
     *
     * @param  resource  $handle
     * @param  list<string>  $cells
     */
    private function writeRow($handle, array $cells, string $delimiter, PayrollEncoding $encoding): void
    {
        if (! $encoding->isLatin1()) {
            CsvDialect::writeRow($handle, $cells, $delimiter);

            return;
        }

        $buffer = fopen('php://temp', 'r+b');

        if ($buffer === false) {
            throw new RuntimeException('No se ha podido preparar la transcodificacion del fichero de nomina.');
        }

        try {
            CsvDialect::writeRow($buffer, $cells, $delimiter);
            rewind($buffer);

            fwrite($handle, self::toLatin1((string) stream_get_contents($buffer)));
        } finally {
            fclose($buffer);
        }
    }

    /**
     * UTF-8 a ISO-8859-1, **sin fallar nunca**.
     *
     * Tres intentos en cascada porque `iconv` se comporta distinto segun la
     * biblioteca C de la imagen: con glibc, `//TRANSLIT` aproxima lo que no cabe;
     * con musl puede devolver `false` y ahi `//IGNORE` al menos entrega el resto.
     * El ultimo recurso sustituye todo lo que no sea ASCII por `?`, que es feo y
     * es legible — y sobre todo, es un fichero.
     */
    private static function toLatin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);

        if ($converted === false) {
            $converted = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $text);
        }

        if ($converted === false) {
            $converted = preg_replace('/[^\x00-\x7F]/', '?', $text);
        }

        return \is_string($converted) ? $converted : $text;
    }

    /** El tipo de contenido que corresponde a la codificacion de la plantilla. */
    public static function contentTypeFor(PayrollEncoding $encoding): string
    {
        return $encoding->isLatin1() ? self::CONTENT_TYPE_LATIN1 : self::CONTENT_TYPE_UTF8;
    }
}
