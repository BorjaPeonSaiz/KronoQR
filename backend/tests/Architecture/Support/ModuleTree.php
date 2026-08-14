<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/*
 * Utilidades de lectura del arbol de modulos para las pruebas de arquitectura.
 *
 * Se lee el codigo fuente como texto y no por reflexion a proposito: una prueba
 * de arquitectura debe poder hablar de ficheros que ni siquiera se pueden
 * cargar —un puerto que importa algo inexistente, una clase duplicada en dos
 * modulos—, que es justo el fallo que viene a detectar. La reflexion exige que
 * el autoload resuelva, y para entonces el dano ya esta hecho.
 */
final class ModuleTree
{
    public const array MODULES = [
        'Attendance',
        'Compliance',
        'Workforce',
        'Identity',
        'Reporting',
        'Kiosk',
        'Product',
        'Shared',
    ];

    public static function root(): string
    {
        return \dirname(__DIR__, 3).'/app/Modules';
    }

    /**
     * Ficheros PHP bajo una ruta relativa a app/Modules/, o [] si no existe.
     *
     * @return list<string>
     */
    public static function filesIn(string $relativePath): array
    {
        $path = self::root().'/'.ltrim($relativePath, '/');

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Ficheros PHP de la capa indicada en cada uno de los modulos dados.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function filesInLayer(array $modules, string $layer): array
    {
        $files = [];

        foreach ($modules as $module) {
            $files = [...$files, ...self::filesIn($module.'/'.$layer)];
        }

        return $files;
    }

    /**
     * Los simbolos importados por un fichero: `use A\B\C;` y `use A\B\{C, D};`.
     *
     * @return list<string>
     */
    public static function importsOf(string $file): array
    {
        $source = (string) file_get_contents($file);
        $imports = [];

        preg_match_all('/^\s*use\s+(?:function\s+|const\s+)?([^;]+);/m', $source, $matches);

        foreach ($matches[1] as $clause) {
            $clause = trim($clause);

            // use A\B\{C, D};
            if (preg_match('/^(.*)\\\\\{(.+)\}$/s', $clause, $group) === 1) {
                foreach (explode(',', $group[2]) as $leaf) {
                    $imports[] = $group[1].'\\'.trim(explode(' as ', trim($leaf))[0]);
                }

                continue;
            }

            $imports[] = trim(explode(' as ', $clause)[0]);
        }

        return $imports;
    }

    /**
     * Rutas de los ficheros que declaran el simbolo dado (interface, class o enum).
     *
     * @return list<string>
     */
    public static function declarationsOf(string $symbol): array
    {
        $pattern = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*'
            .'(?:interface|class|enum|trait)\s+'
            .preg_quote($symbol, '/')
            .'\b/m';

        $found = [];

        foreach (self::filesIn('') as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * Ruta relativa a app/Modules/, para que el mensaje de fallo sea legible.
     */
    public static function relative(string $file): string
    {
        return str_replace('\\', '/', str_replace(self::root().\DIRECTORY_SEPARATOR, '', $file));
    }
}
