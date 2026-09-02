<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Workforce\Application\Port\EmployeeImportSource;
use App\Modules\Workforce\Domain\Exception\UnreadableImportFile;
use Generator;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Lee el fichero de plantilla con `spatie/simple-excel` (doc 02 §3.1,
 * **RF-GP-05**).
 *
 * ## En streaming, de verdad
 *
 * `getRows()` devuelve una `LazyCollection` sobre OpenSpout, que va leyendo el
 * fichero segun se consume. Aqui se reexpone como `Generator`, asi que el
 * contenido completo no llega a estar en memoria en ningun momento. Lo que si
 * crece con el fichero es el informe que construye quien llama, y por eso hay un
 * tope de lineas.
 *
 * ## El delimitador se detecta, no se configura
 *
 * Un Excel espanol exporta con `;` y uno ingles con `,`. Preguntarselo a quien
 * importa seria pedirle que acierte a ciegas un detalle que el propio fichero
 * dice: se cuenta que separador aparece mas veces en la primera linea y se elige
 * ese. Sobre una cabecera con siete columnas la decision no admite empate real,
 * y ante la duda gana la coma, que es lo que dice el estandar.
 *
 * ## La codificacion tambien
 *
 * «Guardar como CSV» en un Windows en espanol produce `Windows-1252`, no UTF-8.
 * El sintoma de no detectarlo son los apellidos con la ñ rota, y nadie lo
 * relaciona con un parametro de configuracion: se comprueba si el fichero es
 * UTF-8 valido y, si no lo es, se lee como `Windows-1252`, que es el unico otro
 * caso realista.
 *
 * ## Nada de esto se aplica al XLSX
 *
 * Un XLSX trae su codificacion dentro y no tiene delimitador. La deteccion mira
 * la extension antes de tocar nada.
 *
 * ## Que se registra si el fichero no se puede leer
 *
 * **El motivo tecnico, nunca el contenido** (regla dura 21). Un fichero de
 * plantilla ilegible sigue siendo un fichero lleno de nombres y documentos de
 * identidad: lo que sube al mensaje de la excepcion es la clase del fallo, no la
 * linea que lo produjo.
 */
final class SimpleExcelImportSource implements EmployeeImportSource
{
    /** Separadores que se consideran al detectar el del CSV, en orden de desempate. */
    private const array DELIMITERS = [',', ';', "\t", '|'];

    private bool $truncated = false;

    public function checksum(string $path): string
    {
        $digest = @hash_file('sha256', $path);

        if (! \is_string($digest)) {
            throw new UnreadableImportFile('the file cannot be read from disk');
        }

        return $digest;
    }

    public function headers(string $path): array
    {
        try {
            $headers = $this->readerFor($path)->getHeaders();
        } catch (Throwable $exception) {
            throw new UnreadableImportFile($exception::class);
        }

        if ($headers === null || $headers === []) {
            throw new UnreadableImportFile('the file has no header row');
        }

        return array_values(array_map(
            static fn (mixed $header): string => \is_scalar($header) ? trim((string) $header) : '',
            $headers,
        ));
    }

    public function rows(string $path, int $maxRows): iterable
    {
        $this->truncated = false;

        return $this->stream($path, $maxRows);
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function stream(string $path, int $maxRows): Generator
    {
        $read = 0;

        try {
            $rows = $this->readerFor($path)->getRows();
        } catch (Throwable $exception) {
            throw new UnreadableImportFile($exception::class);
        }

        foreach ($rows as $row) {
            if (! \is_array($row)) {
                // `LazyCollection` no promete el tipo de cada elemento. Con la
                // cabecera procesada siempre es un array asociativo; una fila que
                // no lo sea se salta en lugar de romper la importacion entera.
                continue;
            }

            if ($read >= $maxRows) {
                // Se deja de leer AQUI, no despues de haberlo leido entero: un
                // fichero de un millon de filas no puede costar un millon de
                // iteraciones para acabar diciendo que era demasiado grande.
                $this->truncated = true;

                return;
            }

            $read++;

            // La primera fila de datos es la LINEA 2 del fichero, porque la 1 es
            // la cabecera. Es el numero que la persona ve al abrirlo en su hoja
            // de calculo, y el unico con el que puede localizar la fila.
            yield $read + 1 => $this->normaliseRow($row);
        }
    }

    /**
     * @param  array<mixed, mixed>  $row
     * @return array<string, string>
     */
    private function normaliseRow(array $row): array
    {
        $values = [];

        foreach ($row as $header => $value) {
            if (! \is_string($header)) {
                continue;
            }

            // Todo a cadena: una celda de XLSX puede llegar como numero o como
            // `DateTimeImmutable`, y quien decide que significa es la capa de
            // aplicacion, no esta. Las fechas se serializan en ISO, que es el
            // primer formato que el planificador reconoce.
            $values[trim($header)] = match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                \is_scalar($value) => self::asUtf8(trim((string) $value)),
                default => '',
            };
        }

        return $values;
    }

    /**
     * Red de seguridad: **lo que sale de aqui es UTF-8 valido, siempre**.
     *
     * La deteccion de codificacion acierta en los dos casos que existen de
     * verdad, pero no puede acertar en todos: un fichero con una codificacion
     * exotica, o mezclada, dejaria bytes invalidos en el informe. Y ese informe
     * se serializa como JSON, asi que un solo byte suelto convierte una
     * importacion con cuarenta lineas correctas en un error del servidor cuyo
     * mensaje —«Malformed UTF-8 characters»— no se parece en nada a su causa.
     *
     * Se transcodifica desde `Windows-1252` porque es lo unico que aparece en la
     * practica, y porque ese juego asigna un caracter a **todos** los bytes: la
     * conversion no puede fallar. En el peor caso, un apellido sale con un
     * caracter raro y la persona que importa lo ve y lo corrige — que es
     * infinitamente mejor que un `500`.
     */
    private static function asUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Sin comprobar el tipo de vuelta: con una cadena de entrada,
        // `mb_convert_encoding` devuelve cadena, y `Windows-1252` asigna un
        // caracter a los 256 bytes posibles, asi que la conversion no puede
        // fallar. Comprobarlo seria una rama que ninguna prueba podria ejercitar.
        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * ## El tipo se pasa SIEMPRE, y no se deja adivinar por la extension
     *
     * `SimpleExcelReader::create()` elige el lector por la extension del fichero,
     * y **el fichero subido no tiene ninguna**: PHP lo deja en el temporal de la
     * peticion con un nombre como `/tmp/phpA1B2C3`. Sin el segundo argumento, una
     * importacion perfectamente valida muere con `UnsupportedTypeException` — en
     * produccion igual que en una prueba.
     */
    private function readerFor(string $path): SimpleExcelReader
    {
        if ($this->isSpreadsheet($path)) {
            // Un XLSX trae su codificacion dentro y no tiene delimitador.
            return SimpleExcelReader::create($path, 'xlsx');
        }

        return SimpleExcelReader::create($path, 'csv')
            ->useDelimiter($this->detectDelimiter($path))
            ->useEncoding($this->detectEncoding($path));
    }

    /**
     * ¿Es un XLSX? Se mira el CONTENIDO, no el nombre.
     *
     * Un XLSX es un ZIP y empieza por `PK\x03\x04`. Fiarse de la extension que
     * envia el cliente tendria dos fallos, y los dos ocurren: el fichero subido
     * no la conserva —vive en el temporal de la peticion sin extension—, y quien
     * exporta desde Excel acaba a menudo con un `.csv` que en realidad es un
     * libro, o al reves. Los dos bytes magicos no admiten confusion.
     */
    private function isSpreadsheet(string $path): bool
    {
        return str_starts_with($this->firstLine($path), "PK\x03\x04");
    }

    /**
     * El separador que mas aparece en la cabecera.
     *
     * Se mira **solo la primera linea**: es donde estan los nombres de columna,
     * que no llevan comas dentro, mientras que una fila de datos si puede
     * llevarlas entrecomilladas y falsear el recuento.
     */
    private function detectDelimiter(string $path): string
    {
        $header = $this->firstLine($path);
        $best = self::DELIMITERS[0];
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($header, $delimiter);

            // `>` y no `>=`: en caso de empate gana el primero del orden, que es
            // la coma. Un empate real solo ocurre con cero de cada uno —una sola
            // columna—, y entonces da igual cual se elija.
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * UTF-8 o `Windows-1252`, decidido sobre los bytes del fichero.
     *
     * ## Por que NO basta con mirar la cabecera
     *
     * Porque los nombres de columna son ASCII puro casi siempre —`nombre`,
     * `apellidos`, `dni`— y el ASCII es UTF-8 valido: un fichero exportado en
     * `Windows-1252` con la primera «ñ» en la linea 300 se daria por UTF-8 y los
     * apellidos llegarian rotos. Peor todavia, la respuesta JSON con esos bytes
     * **no se puede serializar** y el endpoint devuelve un error que no se parece
     * en nada a su causa. Costo una prueba en rojo descubrirlo.
     *
     * ## Aqui SI se lee el fichero entero, y es la unica vez
     *
     * Y no es una excepcion a la lectura en streaming: lo que se lee en streaming
     * son las **filas**, que es donde el tamaño puede dispararse. Esta lectura
     * esta acotada por `workforce.import.max_file_kilobytes` (4 MB de serie), que
     * el `FormRequest` comprueba **antes** de llegar aqui, asi que el techo de
     * memoria es conocido y pequeño.
     *
     * Se probo a recorrerlo por bloques con un arrastre de tres bytes para no
     * partir una secuencia UTF-8 por la mitad, y **estaba mal**: una secuencia de
     * cuatro bytes que empiece justo antes del corte deja su byte inicial en el
     * bloque comprobado, que entonces «no es UTF-8» siendo perfectamente valido.
     * Lo cazo una prueba —el fichero terminaba en «RECEPCIÓN»— y la leccion es la
     * de siempre: la version correcta y aburrida gana a la lista, sobre todo
     * cuando el limite que la hace segura ya existe.
     */
    private function detectEncoding(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new UnreadableImportFile('the file cannot be opened');
        }

        return mb_check_encoding($contents, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private function firstLine(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new UnreadableImportFile('the file cannot be opened');
        }

        // 8 KB: una cabecera con veinte columnas no llega ni a 500 bytes, y el
        // tope evita que un fichero sin saltos de linea se lea entero aqui.
        $line = fgets($handle, 8192);

        fclose($handle);

        return \is_string($line) ? $line : '';
    }
}
