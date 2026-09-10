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
 *
 * Y SE RECORRE CON `scandir`, NUNCA CON `RecursiveDirectoryIterator`. Sobre el
 * *bind mount* de Docker Desktop (NTFS -> contenedor) el iterador **pierde
 * ficheros sin avisar**: en `tests/Feature` se comio 38 de 116 (ver
 * `TestDiscoveryTest`) y en `app/` se come los 45 de
 * `Product/Domain/ValueObject`. Aqui el efecto es peor que en las pruebas,
 * porque una prueba de arquitectura que no ve un fichero **pasa en verde**: no
 * hay nada que denunciar en un fichero que no existe. La regla se seguia
 * comprobando sobre 52 de los 97 ficheros del modulo y ninguna cifra lo decia.
 * `SourceDiscoveryTest` vigila que el fenomeno siga acotado a esto.
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

        return self::phpFilesUnder($path);
    }

    /**
     * Todos los `.php` bajo una ruta absoluta, recursivo y ordenados.
     *
     * Es el UNICO recorrido de directorios de las pruebas de arquitectura, y va
     * con `scandir` por lo que dice el docblock de la clase. Devuelve [] si la
     * ruta no es un directorio, para que quien llama no tenga que comprobarlo.
     *
     * @return list<string>
     */
    public static function phpFilesUnder(string $directory): array
    {
        // La barra final se quita SIEMPRE: `filesIn('')` llega aqui como
        // `…/app/Modules/`, y concatenar produciria `…/app/Modules//Attendance/…`.
        // El iterador normalizaba y `scandir` no, asi que sin esto `relative()`
        // no recorta la raiz y media suite de arquitectura se pone roja por una
        // barra.
        $directory = rtrim($directory, '/');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $files = [...$files, ...self::phpFilesUnder($path)];

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $path;
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
     * Ruta relativa a app/Modules/ —o a la raiz que se le indique—, para que el
     * mensaje de fallo sea legible **y para que una excepcion por fichero pueda
     * compararse por ruta completa**.
     *
     * El segundo parametro existe por `OutboundChannelsTest`: alli tambien se
     * recorre `app/Support`, `app/Http`… que no estan bajo `app/Modules`, y las
     * excepciones se comparaban por `basename()`. Un `basename` no identifica un
     * fichero: cualquier `HttpLokiTransport.php` nuevo en otro directorio del
     * armazon heredaba la excepcion de un canal saliente sin que nadie lo
     * decidiera.
     */
    public static function relative(string $file, ?string $root = null): string
    {
        $root = rtrim($root ?? self::root(), '/\\');
        $file = str_replace('\\', '/', $file);

        return ltrim(str_replace(str_replace('\\', '/', $root), '', $file), '/');
    }
}
