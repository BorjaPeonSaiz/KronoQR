<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Port\ReportExportStorage;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;

/**
 * Los informes en diferido, en el sistema de ficheros local (**RF-IN-06**,
 * decision 4 de la ficha 3.9).
 *
 * ## Por que el disco local y no `Storage::disk()`
 *
 * Porque el producto se despliega entero en el servidor del cliente (ADR-016) y
 * no hay ningun almacenamiento remoto en la pila. Un disco de Laravel añadiria
 * una capa de configuracion que ninguna instalacion usa y que ocultaria lo unico
 * que aqui importa: los permisos.
 *
 * ## `0700` en el directorio y `0600` en el fichero
 *
 * Los mismos que la exportacion integra y que el paquete de diagnostico, y por el
 * mismo motivo: el contenido son **horas trabajadas de personas identificadas**.
 * Solo el usuario de la aplicacion puede leerlo; ni el grupo, ni el resto del
 * sistema, ni un proceso de copia mal configurado.
 *
 * ## Un subdirectorio por exportacion
 *
 * `REPORTING_EXPORT_PATH/<uuid>/<nombre>`. Asi el nombre del fichero puede ser el
 * mismo que en la descarga sincrona —periodo y extension, sin datos personales
 * (regla dura 21)— sin que dos informes del mismo mes se pisen, y la purga borra
 * el directorio entero sin tener que recomponer nada.
 *
 * ## `delete()` es tolerante
 *
 * Borrar lo que ya no esta no es un error: alguien pudo vaciar el directorio a
 * mano para hacer sitio, y lo que la fila debe reflejar es que **ya no se puede
 * descargar**, que es cierto en los dos casos. Lo contrario dejaria filas
 * `completed` para siempre ofreciendo una descarga que responde `404`.
 */
final readonly class FilesystemReportExportStorage implements ReportExportStorage
{
    /** Bloque de lectura de la huella. 1 MiB: constante en memoria para cualquier tamaño. */
    private const int DIGEST_CHUNK = 1048576;

    public function __construct(
        /** `REPORTING_EXPORT_PATH`, **fuera de `public/`** (ver el puerto). */
        private string $root,
    ) {}

    public function pathFor(string $uuid, string $fileName): string
    {
        $directory = $this->directoryFor($uuid);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            // La segunda comprobacion de `is_dir` no sobra: dos trabajos que
            // arrancan a la vez pueden crear el mismo arbol, y ahi `mkdir` falla
            // sin que haya nada roto.
            throw ReportExportWriteFailed::of('no se pudo crear el directorio de informes');
        }

        $path = $directory.\DIRECTORY_SEPARATOR.basename($fileName);

        /*
         * El fichero nace con `0600` ANTES de escribirse: crearlo con la umask del
         * proceso y arreglarlo despues deja una ventana —corta, pero real— en la
         * que las horas de la plantilla son legibles por todo el sistema.
         */
        if (! is_file($path)) {
            @touch($path);
            @chmod($path, 0600);
        }

        return $path;
    }

    public function exists(string $path): bool
    {
        return $path !== '' && is_file($path);
    }

    public function sizeOf(string $path): int
    {
        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    public function digestOf(string $path): string
    {
        /*
         * Por bloques y no `hash_file()` a secas —que tambien lo hace— para dejar
         * escrito que aqui NO se carga el fichero en memoria: un trimestre de 500
         * personas son decenas de megabytes, y el trabajador de cola comparte
         * servidor con el que atiende el fichaje.
         */
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw ReportExportWriteFailed::of('no se pudo leer el fichero para calcular su huella');
        }

        $context = hash_init('sha256');

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::DIGEST_CHUNK);

                if ($chunk === false) {
                    throw ReportExportWriteFailed::of('la lectura del fichero se corto al calcular su huella');
                }

                hash_update($context, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return hash_final($context);
    }

    public function deleteAllFor(string $uuid): void
    {
        $directory = $this->directoryFor($uuid);

        if (! is_dir($directory)) {
            return;
        }

        /*
         * Un nivel y **sin recursion**: el arbol que este adaptador crea es
         * exactamente `<raiz>/<uuid>/<fichero>`, asi que un borrado recursivo no
         * haria falta y si abriria la posibilidad de vaciar mas de lo que se
         * pretende el dia que alguien apunte `REPORTING_EXPORT_PATH` a un sitio
         * compartido por error.
         */
        foreach (glob($directory.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }

    public function storedUuids(): array
    {
        $root = rtrim($this->root, '/\\');

        if (! is_dir($root)) {
            return [];
        }

        $uuids = [];

        foreach (glob($root.\DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
            $uuids[] = basename($directory);
        }

        return $uuids;
    }

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }

        // Y el subdirectorio de la exportacion, si queda vacio. `@rmdir` falla en
        // silencio cuando no lo esta, que es lo correcto: nunca se borra
        // recursivamente nada bajo `REPORTING_EXPORT_PATH`.
        $directory = \dirname($path);

        if (is_dir($directory) && $directory !== rtrim($this->root, '/\\')) {
            @rmdir($directory);
        }
    }

    /** El subdirectorio de esa exportacion: `<raiz>/<uuid>`. */
    private function directoryFor(string $uuid): string
    {
        return rtrim($this->root, '/\\').\DIRECTORY_SEPARATOR.$uuid;
    }
}
