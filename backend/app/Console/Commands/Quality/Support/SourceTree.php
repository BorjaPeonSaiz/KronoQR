<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

/**
 * Los ficheros de codigo de una lista de directorios, que admiten los comodines
 * de `glob()`: un asterisco en lugar del modulo recorre los ocho.
 *
 * **Se recorre con `scandir` y no con `RecursiveDirectoryIterator`** (ni con
 * Finder, que lo usa por debajo). Sobre el *bind mount* de Docker Desktop el
 * iterador devuelve menos ficheros de los que hay, sin avisar
 * (`tests/Architecture/SourceDiscoveryTest.php`), y aqui eso seria un verde
 * falso: un controlador que no se ve tampoco se comprueba.
 *
 * **Un patron que no encuentra ningun directorio se dice, no se calla.** Es la
 * misma regla que `qa:traceability` aplica a los frontends: una exploracion
 * parcial que da verde no comprueba nada, solo enseña donde se ejecuto. Quien
 * mueve un directorio tiene que mover tambien su entrada en config/quality.php.
 */
final readonly class SourceTree
{
    /** Las extensiones del codigo de produccion de los cuatro artefactos. */
    private const array EXTENSIONS = ['php', 'ts', 'vue', 'js'];

    /** Lo que nunca es codigo propio, aunque cuelgue de un directorio vigilado. */
    private const array SKIPPED = ['.', '..', 'node_modules'];

    /**
     * @param  array<string, string>  $files  Ruta absoluta -> contenido.
     * @param  list<string>  $missing  Patrones que no encontraron ningun directorio.
     */
    private function __construct(public array $files, public array $missing) {}

    /**
     * @param  list<string>  $patterns
     */
    public static function of(array $patterns): self
    {
        $files = [];
        $missing = [];

        foreach ($patterns as $pattern) {
            $found = glob($pattern, GLOB_ONLYDIR);

            if ($found === false || $found === []) {
                $missing[] = $pattern;

                continue;
            }

            foreach ($found as $directory) {
                self::collect($directory, $files);
            }
        }

        ksort($files);

        return new self($files, $missing);
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function collect(string $directory, array &$files): void
    {
        $entries = scandir($directory);

        foreach ($entries === false ? [] : $entries as $entry) {
            if (\in_array($entry, self::SKIPPED, true)) {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                self::collect($path, $files);
            } elseif (\in_array(pathinfo($path, PATHINFO_EXTENSION), self::EXTENSIONS, true)) {
                $files[$path] = (string) file_get_contents($path);
            }
        }
    }
}
