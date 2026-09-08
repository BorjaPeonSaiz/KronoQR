<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Export;

use App\Modules\Product\Application\Port\DataExportArchiveWriter;
use App\Modules\Product\Domain\Exception\DataExportWriteFailed;
use App\Modules\Product\Domain\ValueObject\DataExportArchive;
use App\Modules\Product\Domain\ValueObject\DataExportFile;
use App\Modules\Product\Domain\ValueObject\DataExportFormat;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use ZipArchive;

/**
 * Escribe los ficheros de la exportacion integra y los comprime (**RF-PD-14**,
 * RL-20).
 *
 * ## Fila a fila, nunca en memoria
 *
 * Cada `writeDataset()` abre un descriptor, escribe la cabecera y va volcando lo
 * que le cede el cursor de servidor. En ningun momento hay mas de un lote en
 * memoria, y por eso 90 dias y cuatro años cuestan lo mismo.
 *
 * La huella se calcula **releyendo el fichero ya cerrado** con `hash_file()`, no
 * acumulando en memoria: es una pasada de lectura secuencial y garantiza que lo
 * que se firma es lo que hay en el disco, no lo que se creia estar escribiendo.
 *
 * ## El ZIP se cierra sobre ficheros ya escritos
 *
 * `ZipArchive::addFile()` lee del disco al comprimir, asi que tampoco aqui entra
 * nada entero en memoria. **Sin cifrar y sin contraseña**, por lo mismo que el
 * paquete de diagnostico: el cliente tiene que poder abrirlo, mirarlo y cargarlo
 * en otra herramienta sin depender de nadie — que es exactamente lo que RL-20
 * promete. Lo que protege el fichero son los permisos del disco y el hecho de
 * que caduca.
 *
 * ## Permisos: directorio `0700`, fichero `0600`
 *
 * Mas estricto que el paquete de diagnostico y por una razon mas fuerte: aquel
 * puede llevar datos personales y **este los lleva siempre**. Un ZIP legible por
 * cualquier cuenta del servidor seria la plantilla entera al alcance de quien
 * pase por ahi.
 *
 * ## Falla ruidosamente y con la ruta en el mensaje
 *
 * Aqui no hay nada que degradar: si el fichero no se puede escribir no hay
 * exportacion, y quien esta delante de la terminal —o quien lea `failure_reason`
 * en el panel— tiene que saber **que directorio** arreglar. El fabricante no
 * tiene acceso a este servidor (ADR-016): un mensaje que no diga que hacer deja
 * como unica salida una llamada de telefono.
 */
final readonly class ZipDataExportArchiveWriter implements DataExportArchiveWriter
{
    public function __construct(private string $directory) {}

    public function begin(string $exportUuid): string
    {
        $workspace = $this->path('.work-'.$exportUuid);

        // Un resto de un intento anterior con el mismo UUID no puede existir
        // —el UUID es nuevo cada vez— pero si el proceso murio a mitad y alguien
        // reintenta a mano, mezclar dos generaciones daria un ZIP con ficheros
        // de dos momentos distintos.
        $this->discard($workspace);
        $this->makeDirectory($workspace);

        return $workspace;
    }

    public function writeDataset(string $workspace, ExportedDataset $dataset, iterable $rows, string $locale): DataExportFile
    {
        $path = $workspace.'/'.$dataset->fileName();

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new DataExportWriteFailed(
                'No se pudo escribir «'.$dataset->fileName().'» de la exportacion integra en '.$workspace
                .'. Comprueba que el directorio existe y que el usuario de la aplicacion puede escribir en el.'
            );
        }

        try {
            $written = $dataset->format === DataExportFormat::Csv
                ? $this->writeCsv($handle, $dataset, $rows, $locale)
                : $this->writeJson($handle, $dataset, $rows);
        } finally {
            fclose($handle);
        }

        @chmod($path, 0o600);

        return $this->fileAt($path, $dataset->fileName(), $written, $dataset->name);
    }

    public function writeDocument(string $workspace, string $fileName, string $contents): DataExportFile
    {
        $path = $workspace.'/'.$fileName;

        if (@file_put_contents($path, $contents) === false) {
            throw new DataExportWriteFailed(
                'No se pudo escribir «'.$fileName.'» de la exportacion integra en '.$workspace
                .'. Comprueba que el directorio existe y que el usuario de la aplicacion puede escribir en el.'
            );
        }

        @chmod($path, 0o600);

        // Cero filas de datos a proposito: `manifest.json` y `README.md` no son
        // datos, y sumarlos falsearia el recuento que el cliente compara contra
        // su base de datos.
        return $this->fileAt($path, $fileName, 0);
    }

    public function seal(string $workspace, string $fileName): DataExportArchive
    {
        $this->makeDirectory($this->directory);

        $target = $this->path($fileName);

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DataExportWriteFailed(
                'No se pudo crear el ZIP de la exportacion integra en '.$target
                .'. Comprueba el espacio libre y los permisos del directorio.'
            );
        }

        foreach ($this->filesIn($workspace) as $path) {
            if (! $zip->addFile($path, basename($path))) {
                $zip->close();

                throw new DataExportWriteFailed('No se pudo añadir «'.basename($path).'» al ZIP de la exportacion integra.');
            }
        }

        if (! $zip->close()) {
            throw new DataExportWriteFailed(
                'No se pudo cerrar el ZIP de la exportacion integra en '.$target
                .'. Lo mas probable es que el disco se haya quedado sin espacio.'
            );
        }

        @chmod($target, 0o600);

        $sha256 = hash_file('sha256', $target);
        $size = filesize($target);

        if ($sha256 === false || $size === false) {
            throw new DataExportWriteFailed('El ZIP de la exportacion integra no se puede releer: '.$target);
        }

        return new DataExportArchive(
            path: (string) (realpath($target) ?: $target),
            fileName: $fileName,
            sha256: $sha256,
            sizeBytes: $size,
        );
    }

    public function discard(string $workspace): void
    {
        if (! is_dir($workspace)) {
            return;
        }

        foreach ($this->filesIn($workspace) as $path) {
            @unlink($path);
        }

        @rmdir($workspace);
    }

    public function delete(string $path): bool
    {
        if (! is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    /**
     * El CSV: BOM, cabecera con los nombres de columna y una fila por registro.
     *
     * **La cabecera lleva los nombres tecnicos de las columnas, no rotulos
     * traducidos.** Es lo contrario de lo que hace la exportacion para la
     * Inspeccion, y a proposito: aquella la lee una persona y esta la carga un
     * programa. Un `SELECT` sobre el fichero, o un `COPY` a otra base de datos,
     * necesita nombres estables que no cambien con el idioma del panel. Lo que
     * explica cada columna es el `README.md`, que si esta traducido.
     *
     * @param  resource  $handle
     * @param  iterable<array<string, mixed>>  $rows
     * @return int Filas de datos escritas, sin contar la cabecera.
     */
    private function writeCsv($handle, ExportedDataset $dataset, iterable $rows, string $locale): int
    {
        CsvDialect::writeByteOrderMark($handle);

        // El delimitador que espera la hoja de calculo de quien habla el idioma
        // de la INSTALACION: `;` en español, `,` en ingles. Es la misma decision
        // que la exportacion de conveniencia (RF-IN-04); los dos documentos con
        // efectos legales se quedan con el `;` de siempre porque se entregan a un
        // tercero español.
        $delimiter = CsvDialect::delimiterFor($locale);

        CsvDialect::writeRow($handle, $dataset->columns(), $delimiter);

        $written = 0;

        foreach ($rows as $row) {
            CsvDialect::writeRow(
                $handle,
                array_map(self::text(...), $dataset->allowlist->apply($row)),
                $delimiter,
            );

            $written++;
        }

        return $written;
    }

    /**
     * El JSON: una lista de objetos, escrita elemento a elemento.
     *
     * Lista tambien cuando solo hay una fila —`site`, `license`— para que quien
     * lo consuma no tenga que tratar dos formas. Se escribe por partes y no con
     * un `json_encode` del conjunto por la misma razon que el CSV: nada entero
     * en memoria, aunque estos conjuntos sean pequeños hoy.
     *
     * @param  resource  $handle
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function writeJson($handle, ExportedDataset $dataset, iterable $rows): int
    {
        fwrite($handle, "[\n");

        $written = 0;

        foreach ($rows as $row) {
            $allowed = $dataset->allowlist->apply($row);
            $document = [];

            foreach ($allowed as $column => $value) {
                $document[$column] = $dataset->embedsJson($column)
                    ? self::decoded($value)
                    : self::nullableText($value);
            }

            fwrite($handle, ($written > 0 ? ",\n" : '').json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            $written++;
        }

        fwrite($handle, "\n]\n");

        return $written;
    }

    /**
     * Un documento `jsonb` leido como texto, incrustado como documento.
     *
     * Si no se puede interpretar se devuelve el texto tal cual en lugar de
     * lanzar: un valor raro en una fila no puede impedir que el cliente se lleve
     * el resto de sus datos, y entregarlo en crudo conserva la informacion.
     */
    private static function decoded(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /** Para el CSV: nulo es celda vacia, que es lo que espera una hoja de calculo. */
    private static function text(mixed $value): string
    {
        return self::nullableText($value) ?? '';
    }

    private static function nullableText(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            // Cualquier otra cosa —un objeto del driver— se serializa antes que
            // perderse: es preferible una celda con JSON dentro a una vacia que
            // parezca un dato que no existia.
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
        };
    }

    /**
     * Los ficheros de un directorio, ordenados: el ZIP sale con el mismo orden
     * en dos generaciones de la misma instalacion, que es lo que permite
     * compararlas.
     *
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $entries = glob(rtrim($directory, '/').'/*') ?: [];

        $files = array_values(array_filter($entries, is_file(...)));

        sort($files, SORT_STRING);

        return $files;
    }

    private function fileAt(string $path, string $name, int $rows, ?string $dataset = null): DataExportFile
    {
        $sha256 = hash_file('sha256', $path);
        $size = filesize($path);

        if ($sha256 === false || $size === false) {
            throw new DataExportWriteFailed('El fichero «'.$name.'» de la exportacion integra no se puede releer: '.$path);
        }

        return new DataExportFile($name, $rows, $sha256, $size, $dataset);
    }

    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new DataExportWriteFailed(
                'No se pudo crear el directorio de exportaciones '.$path
                .'. Crealo a mano con «mkdir -p» y dale permisos al usuario de la aplicacion, '
                .'o cambia PRODUCT_DATA_EXPORT_PATH en el .env.'
            );
        }
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/').'/'.$name;
    }
}
