<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use RuntimeException;

/**
 * Un directorio temporal propio para cada pasada de la suite, que se borra
 * entero cuando el proceso termina (Q10 de la verificacion de la 2.1.0).
 *
 * POR QUE EXISTE. Casi cien ficheros de prueba y de ayudantes crean ficheros en
 * `sys_get_temp_dir()` —el logo de marca, la plantilla de importacion, la
 * metrica en fichero de texto, los scripts de instalacion con `mktemp`— y muy
 * pocos los borraban. El `/tmp` del contenedor llego a quince mil entradas:
 * cada pasada de la suite Unit dejaba 39 directorios de marca y cada pasada de
 * Feature casi noventa plantillas de importacion. Arreglarlo prueba a prueba
 * exige que nadie olvide nunca un `finally`; aqui se arregla una vez.
 *
 * COMO. `tests/bootstrap.php` lo instala antes de cargar nada: crea
 * `<TMPDIR>/kronoqr-suite-<pid>-<azar>` y apunta `TMPDIR` a el. PHP lee
 * `TMPDIR` la primera vez que alguien llama a `sys_get_temp_dir()` y lo
 * recuerda, asi que todo lo que la suite escriba alli —y todo proceso hijo,
 * `bash` o `php artisan`, que hereda el entorno— cae dentro. Al terminar el
 * proceso que lo creo, se borra.
 *
 * SOLO LO BORRA QUIEN LO CREO. Los hijos de `pcntl_fork` heredan la funcion
 * de apagado; si un hijo saliera con `exit` borraria el directorio del padre a
 * mitad de prueba. Por eso se compara el pid.
 *
 * Y LO QUE UN PROCESO MATADO NO LLEGO A BORRAR lo recoge la pasada siguiente:
 * un directorio de la suite cuyo pid ya no existe es de una pasada que murio
 * (un `SIGKILL`, un tiempo agotado). El de una pasada viva —otra suite que
 * corre a la vez en el mismo contenedor— no se toca.
 *
 * LO QUE NO CUBRE, Y ESTA ESCRITO: una ruta escrita a mano (`'/tmp/...'`), un
 * proceso hijo lanzado con un entorno vacio, y una instalacion con
 * `sys_temp_dir` fijado en el php.ini, que PHP antepone a `TMPDIR`. En ese
 * ultimo caso no se instala nada y `SuiteTemporaryDirectoryTest` lo hace ver.
 */
final class SuiteTemporaryDirectory
{
    public const string PREFIX = 'kronoqr-suite-';

    /**
     * Crea el directorio de la pasada y apunta `TMPDIR` a el.
     *
     * @return string El directorio creado.
     */
    public static function install(): string
    {
        // No se llama a `sys_get_temp_dir()`: PHP recuerda su primer resultado,
        // y despues ya no veria el `TMPDIR` nuevo.
        $base = self::systemBase();

        self::collectAbandoned($base);

        $directory = $base.'/'.self::PREFIX.getmypid().'-'.bin2hex(random_bytes(4));

        if (! mkdir($directory, 0o700) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio temporal de la suite '.$directory.'.');
        }

        putenv('TMPDIR='.$directory);
        $_ENV['TMPDIR'] = $directory;
        $_SERVER['TMPDIR'] = $directory;

        $owner = getmypid();

        register_shutdown_function(static function () use ($directory, $owner): void {
            if (getmypid() === $owner) {
                self::remove($directory);
            }
        });

        return $directory;
    }

    /**
     * Borra los directorios de pasadas cuyo proceso ya no existe.
     */
    public static function collectAbandoned(string $base): void
    {
        $leftovers = glob($base.'/'.self::PREFIX.'*', GLOB_ONLYDIR);

        foreach ($leftovers === false ? [] : $leftovers as $directory) {
            if (preg_match('/^'.preg_quote(self::PREFIX, '/').'(\d+)-/', basename($directory), $match) !== 1) {
                continue;
            }

            if (! self::isAlive((int) $match[1])) {
                self::remove($directory);
            }
        }
    }

    public static function remove(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        // Alguna prueba deja un directorio sin permisos a proposito para
        // provocar un fallo de escritura: sin devolverselos no se puede ni
        // listar.
        @chmod($directory, 0o700);
        $names = @scandir($directory);

        foreach ($names === false ? [] : array_diff($names, ['.', '..']) as $name) {
            $path = $directory.'/'.$name;

            if (is_dir($path) && ! is_link($path)) {
                self::remove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private static function systemBase(): string
    {
        $configured = getenv('TMPDIR');

        return rtrim(\is_string($configured) && $configured !== '' ? $configured : '/tmp', '/');
    }

    private static function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (\function_exists('posix_kill')) {
            // Senal 0: no envia nada, solo pregunta si el proceso existe. EPERM
            // (1) es «existe, pero es de otro usuario».
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }

        return is_dir('/proc/'.$pid);
    }
}
