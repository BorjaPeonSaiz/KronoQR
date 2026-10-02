<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

/**
 * Un directorio temporal propio para las pruebas de la conciliacion de ficheros
 * generados (ADR-045).
 *
 * Las purgas borran ficheros de verdad, asi que cada prueba trabaja en su propio
 * arbol bajo `sys_get_temp_dir()` y lo retira al terminar. **El borrado final no
 * sigue enlaces simbolicos**: varias pruebas dejan un enlace que apunta fuera
 * del arbol para comprobar que la purga no lo sigue, y la limpieza no puede ser
 * la que lo haga.
 */
final class GeneratedFilesSandbox
{
    /** @var list<string> */
    private static array $roots = [];

    /** Un directorio nuevo y vacio. */
    public static function directory(string $label): string
    {
        $path = sys_get_temp_dir().'/kronoqr-generated-files-'.$label.'-'.bin2hex(random_bytes(6));

        mkdir($path, 0o700, true);

        self::$roots[] = $path;

        return $path;
    }

    /** Escribe un fichero, creando su directorio si hace falta, y devuelve su ruta. */
    public static function file(string $path, string $contents = 'contenido de prueba'): string
    {
        if (! is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /** Pone el `mtime` de una entrada `$seconds` segundos antes (o despues, si es negativo) de ahora. */
    public static function touchAgo(string $path, int $seconds): void
    {
        touch($path, time() - $seconds);
    }

    /** Retira todo lo creado. Se llama en el `afterEach`. */
    public static function cleanUp(): void
    {
        foreach (self::$roots as $root) {
            self::remove($root);
        }

        self::$roots = [];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path.'/'.$name);
            }
        }

        @rmdir($path);
    }

    /** No se instancia. */
    private function __construct() {}
}
