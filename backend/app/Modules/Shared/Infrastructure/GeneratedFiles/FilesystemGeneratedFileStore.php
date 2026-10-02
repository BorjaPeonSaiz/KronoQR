<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\GeneratedFiles;

use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Domain\ValueObject\FileTimestamps;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileRemoval;
use App\Modules\Shared\Domain\ValueObject\PathOverlap;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Adaptador de {@see GeneratedFileStore} sobre el sistema de ficheros local
 * (ADR-045, condiciones C1, C3 y C9).
 *
 * ## Las cuatro reglas del confinamiento
 *
 * 1. **Un solo nivel** de la raiz de la clase. Nunca se recorre hacia abajo.
 * 2. **Patron exacto** de nombre: lo que no casa no existe para la purga.
 * 3. **Nunca se sigue un enlace simbolico**: se mira con `lstat`/`is_link`, y
 *    una entrada que es un enlace se rechaza entera, aunque su nombre case.
 * 4. Un directorio se borra **vaciando sus ficheros regulares de un nivel y
 *    retirandolo**. Si dentro hay un subdirectorio o un enlace, se aborta esa
 *    entrada antes de borrar nada: o se borra entera o no se toca.
 *
 * Y para las rutas que guarda una fila, una mas: el directorio padre resuelto
 * con `realpath` tiene que ser **exactamente** la raiz de la clase. Una fila que
 * apunte a `BACKUP_PATH/daily/…` no borra ninguna copia.
 *
 * ## Los motivos van al log, sin la ruta
 *
 * Cada rechazo deja un aviso con la clase y el motivo (`outside_root`,
 * `symbolic_link`, `nested_entry`, `foreign_name`, `not_a_file`). Nunca la ruta
 * ni el nombre: el nombre de un informe lleva el periodo y la ruta lleva el
 * `uuid` (regla dura 21), y el log tecnico viaja en el paquete de diagnostico.
 */
final readonly class FilesystemGeneratedFileStore implements GeneratedFileStore
{
    public function __construct(private LoggerInterface $logger = new NullLogger) {}

    public function entries(GeneratedFileArea $area): array
    {
        $root = $this->resolvedRoot($area);

        if ($root === null) {
            return [];
        }

        clearstatcache();

        $entries = [];

        foreach ($this->namesIn($root) as $name) {
            if (! $area->admits($name)) {
                continue;
            }

            $path = $root.'/'.$name;
            $own = $this->timestampsOf($path);

            if ($own === null) {
                continue;
            }

            $timestamps = [$own];

            // Solo se baja a un directorio de verdad: un enlace se lista con sus
            // propias marcas y se rechaza al intentar borrarlo.
            if ($area->holdsDirectories() && ! is_link($path) && is_dir($path)) {
                foreach ($this->namesIn($path) as $child) {
                    $stamp = $this->timestampsOf($path.'/'.$child);

                    if ($stamp !== null) {
                        $timestamps[] = $stamp;
                    }
                }
            }

            $entries[] = new GeneratedFileEntry($name, $timestamps);
        }

        return $entries;
    }

    public function remove(GeneratedFileArea $area, string $name): GeneratedFileRemoval
    {
        if (! $area->admits($name)) {
            return $this->refuse($area, 'foreign_name');
        }

        $root = $this->resolvedRoot($area);

        if ($root === null) {
            return GeneratedFileRemoval::Absent;
        }

        $path = $root.'/'.$name;

        clearstatcache(true, $path);

        if (@lstat($path) === false) {
            return GeneratedFileRemoval::Absent;
        }

        if (is_link($path)) {
            return $this->refuse($area, 'symbolic_link');
        }

        return $area->holdsDirectories()
            ? $this->removeDirectory($area, $path)
            : $this->removeFile($area, $path);
    }

    public function locate(GeneratedFileArea $area, string $recordedPath): RecordedFileLocation
    {
        return $this->resolveRecorded($area, $recordedPath)[0];
    }

    public function discard(GeneratedFileArea $area, string $recordedPath): GeneratedFileRemoval
    {
        [$location, $file] = $this->resolveRecorded($area, $recordedPath);

        if ($location === RecordedFileLocation::OutsideArea) {
            return GeneratedFileRemoval::Refused;
        }

        if ($location === RecordedFileLocation::Missing || $file === null) {
            return GeneratedFileRemoval::Absent;
        }

        if (! @unlink($file)) {
            return GeneratedFileRemoval::Failed;
        }

        if ($area->holdsDirectories()) {
            // El directorio de la entrada, si se ha quedado vacio. `rmdir` falla
            // en silencio cuando no lo esta, que es lo correcto: lo que quede
            // dentro lo decide el barrido de huerfanos con su edad minima.
            @rmdir(\dirname($file));
        }

        return GeneratedFileRemoval::Removed;
    }

    /**
     * Resuelve la ruta de una fila contra la raiz de su clase.
     *
     * @return array{RecordedFileLocation, ?string} El desenlace y, si esta presente, la ruta
     *                                              ya confinada que se puede borrar.
     */
    private function resolveRecorded(GeneratedFileArea $area, string $recordedPath): array
    {
        $parts = self::recordedParts($area, $recordedPath);

        if ($parts === null) {
            return [$this->outside($area, 'foreign_name'), null];
        }

        [$parent, $entryName, $fileName] = $parts;

        $root = $this->resolvedRoot($area);
        $parentReal = realpath($parent);

        if ($root === null || $parentReal === false) {
            // Sin raiz en el disco no puede haber nada que borrar. Se distingue
            // «dentro pero ya no esta» de «fuera» por la forma de la ruta.
            return PathOverlap::normalise($parent) === PathOverlap::normalise($area->root)
                ? [RecordedFileLocation::Missing, null]
                : [$this->outside($area, 'outside_root'), null];
        }

        if (rtrim(str_replace('\\', '/', $parentReal), '/') !== $root) {
            return [$this->outside($area, 'outside_root'), null];
        }

        $entry = $root.'/'.$entryName;

        return $fileName === null
            ? $this->regularFile($area, $entry)
            : $this->fileInsideEntry($area, $entry, $fileName);
    }

    /**
     * La ruta de una fila partida en directorio padre, nombre de la entrada y,
     * en una clase de directorios, nombre del fichero. Nula si el nombre de la
     * entrada no es de la clase o el del fichero no es un nombre.
     *
     * En una clase de ficheros la entrada es el propio fichero; en una de
     * directorios, el directorio que lo contiene.
     *
     * @return array{string, string, ?string}|null
     */
    private static function recordedParts(GeneratedFileArea $area, string $recordedPath): ?array
    {
        $recorded = str_replace('\\', '/', trim($recordedPath));
        $entryPath = $area->holdsDirectories() ? \dirname($recorded) : $recorded;
        $fileName = $area->holdsDirectories() ? basename($recorded) : null;
        $entryName = basename($entryPath);

        if ($recorded === '' || ! $area->admits($entryName) || \in_array($fileName, ['', '.', '..'], true)) {
            return null;
        }

        return [\dirname($entryPath), $entryName, $fileName];
    }

    /** @return array{RecordedFileLocation, ?string} */
    private function fileInsideEntry(GeneratedFileArea $area, string $entry, string $fileName): array
    {
        clearstatcache(true, $entry);

        if (is_link($entry)) {
            return [$this->outside($area, 'symbolic_link'), null];
        }

        if (@lstat($entry) === false) {
            return [RecordedFileLocation::Missing, null];
        }

        if (! is_dir($entry)) {
            return [$this->outside($area, 'not_a_file'), null];
        }

        return $this->regularFile($area, $entry.'/'.$fileName);
    }

    /** @return array{RecordedFileLocation, ?string} */
    private function regularFile(GeneratedFileArea $area, string $path): array
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            return [$this->outside($area, 'symbolic_link'), null];
        }

        if (@lstat($path) === false) {
            return [RecordedFileLocation::Missing, null];
        }

        return is_file($path)
            ? [RecordedFileLocation::Present, $path]
            : [$this->outside($area, 'not_a_file'), null];
    }

    private function removeFile(GeneratedFileArea $area, string $path): GeneratedFileRemoval
    {
        if (! is_file($path)) {
            return $this->refuse($area, 'not_a_file');
        }

        return @unlink($path) ? GeneratedFileRemoval::Removed : GeneratedFileRemoval::Failed;
    }

    /**
     * O se borra entero o no se toca: primero se comprueba todo y despues se
     * borra, para no dejar media entrada si aparece un subdirectorio al final.
     */
    private function removeDirectory(GeneratedFileArea $area, string $path): GeneratedFileRemoval
    {
        if (! is_dir($path)) {
            return $this->refuse($area, 'not_a_file');
        }

        $files = [];

        foreach ($this->namesIn($path) as $child) {
            $childPath = $path.'/'.$child;

            if (is_link($childPath) || ! is_file($childPath)) {
                return $this->refuse($area, 'nested_entry');
            }

            $files[] = $childPath;
        }

        foreach ($files as $file) {
            if (! @unlink($file)) {
                return GeneratedFileRemoval::Failed;
            }
        }

        return @rmdir($path) ? GeneratedFileRemoval::Removed : GeneratedFileRemoval::Failed;
    }

    /** La raiz resuelta, o nula si no existe o no es un directorio. */
    private function resolvedRoot(GeneratedFileArea $area): ?string
    {
        $root = realpath($area->root);

        return $root === false || ! is_dir($root) ? null : rtrim(str_replace('\\', '/', $root), '/');
    }

    /** @return list<string> */
    private function namesIn(string $directory): array
    {
        $names = @scandir($directory);

        if ($names === false) {
            return [];
        }

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
    }

    private function timestampsOf(string $path): ?FileTimestamps
    {
        $stat = @lstat($path);

        if ($stat === false) {
            return null;
        }

        return FileTimestamps::fromEpochSeconds($stat['mtime'], $stat['ctime']);
    }

    private function refuse(GeneratedFileArea $area, string $reason): GeneratedFileRemoval
    {
        $this->warn($area, $reason);

        return GeneratedFileRemoval::Refused;
    }

    private function outside(GeneratedFileArea $area, string $reason): RecordedFileLocation
    {
        $this->warn($area, $reason);

        return RecordedFileLocation::OutsideArea;
    }

    private function warn(GeneratedFileArea $area, string $reason): void
    {
        $this->logger->warning('generated_files.refused', [
            'class' => $area->class->value,
            'reason' => $reason,
        ]);
    }
}
