<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\GeneratedFileArea;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileEntry;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileRemoval;
use App\Modules\Shared\Domain\ValueObject\RecordedFileLocation;

/**
 * El disco donde viven los ficheros que genera el producto, visto por la
 * conciliacion (ADR-045, condiciones C1, C3 y C9).
 *
 * ## Todo esta confinado a la raiz y al patron de la clase
 *
 * Ningun metodo actua fuera de `GeneratedFileArea::$root`, ninguno baja mas de
 * un nivel y **ninguno sigue un enlace simbolico**. Un directorio se borra
 * vaciando sus ficheros regulares de un nivel y retirandolo; si dentro aparece
 * un subdirectorio o un enlace, no se borra nada de esa entrada.
 *
 * ## No decide nada
 *
 * Que es huerfano y a partir de que edad lo decide quien llama, con el reloj
 * inyectado (regla dura 2). El adaptador solo lee marcas de tiempo y borra lo
 * que le piden, si cae dentro de su clase.
 */
interface GeneratedFileStore
{
    /**
     * Las entradas de un nivel de la raiz cuyo nombre casa con el patron de la
     * clase, con sus marcas de tiempo. Vacia si la raiz no existe.
     *
     * @return list<GeneratedFileEntry>
     */
    public function entries(GeneratedFileArea $area): array;

    /** Borra una entrada de la raiz, por su nombre. */
    public function remove(GeneratedFileArea $area, string $name): GeneratedFileRemoval;

    /**
     * Donde esta el fichero que una fila dice tener. No borra nada.
     *
     * Para una clase de ficheros, la ruta es `<raiz>/<entrada>`; para una de
     * directorios, `<raiz>/<entrada>/<fichero>`.
     */
    public function locate(GeneratedFileArea $area, string $recordedPath): RecordedFileLocation;

    /**
     * Borra el fichero que una fila dice tener, **solo** si {@see self::locate()}
     * lo da por `Present`. En una clase de directorios retira ademas el
     * directorio de la entrada si queda vacio.
     */
    public function discard(GeneratedFileArea $area, string $recordedPath): GeneratedFileRemoval;

    /**
     * ¿Existe la raiz de la clase y es un directorio? Sin ella no se concilia:
     * una raiz ausente no dice nada de las filas (ADR-045).
     */
    public function rootExists(GeneratedFileArea $area): bool;
}
