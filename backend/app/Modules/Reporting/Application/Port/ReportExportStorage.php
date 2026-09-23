<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * El sistema de ficheros donde viven los informes generados en diferido
 * (**RF-IN-06**, decision 4 de la ficha 3.9).
 *
 * ## Por que un puerto y no `Storage::` en el caso de uso
 *
 * Porque `Application` no usa facades (doc 02 §3.5, verificado por Deptrac) y
 * porque el caso de uso **no debe saber que hay un disco**: lo que decide es que
 * el fichero se escribe, cuanto ocupa y que huella tiene, no como se crea un
 * directorio con permisos `0700`.
 *
 * ## `REPORTING_EXPORT_PATH` esta fuera de `public/`, y no es un detalle
 *
 * El fichero lleva las horas de personas identificadas. Servido por el servidor
 * web sin pasar por la aplicacion, no habria enlace de un solo uso, ni
 * caducidad, ni asiento en `audit_log`: habria una URL adivinable. El unico
 * camino hacia estos bytes es `GET /reports/exports/{uuid}/download` con su
 * token (ADR-041).
 *
 * ## `delete()` es tolerante a proposito
 *
 * Borrar un fichero que ya no esta no es un error: la purga tiene que poder
 * marcar la fila igualmente. Alguien pudo vaciar el directorio a mano para hacer
 * sitio, y lo que la fila debe reflejar es que **ya no se puede descargar**, que
 * es cierto en los dos casos.
 */
interface ReportExportStorage
{
    /**
     * Prepara la ruta absoluta donde se escribira el fichero, con el directorio
     * ya creado.
     *
     * El nombre **no lleva ningun dato personal** (regla dura 21): el `uuid` de
     * la exportacion, el periodo y la extension. Ni el nombre de quien lo pidio
     * ni el de nadie que salga dentro.
     */
    public function pathFor(string $uuid, string $fileName): string;

    /** ¿Sigue el fichero en el disco? Una limpieza manual deja la fila apuntando a nada. */
    public function exists(string $path): bool;

    /** Tamaño en bytes del fichero ya escrito. */
    public function sizeOf(string $path): int;

    /** SHA-256 en hexadecimal del fichero ya escrito, leido por bloques. */
    public function digestOf(string $path): string;

    /** Borra el fichero. Que no exista no es un error: ver el docblock. */
    public function delete(string $path): void;

    /**
     * Borra **todo** lo que haya escrito esa exportacion, tenga o no fila que lo
     * mencione.
     *
     * Existe para el fichero a medias: si el trabajo muere de una forma que no
     * puede atrapar —`$timeout` agotado, el trabajador sin memoria, un `SIGTERM`
     * durante un despliegue—, la fila nunca llego a `completed` y por tanto nunca
     * guardo su `file_path`. Nadie sabe entonces que ese fichero existe, la purga
     * por caducidad no lo mira —solo recorre filas `completed`— y lo que queda en
     * el disco del cliente es **media plantilla con sus horas** sin plazo y sin
     * dueño.
     *
     * Se borra por `uuid` y no por ruta precisamente porque la ruta es lo que no
     * se sabe.
     */
    public function deleteAllFor(string $uuid): void;

    /**
     * Los `uuid` que tienen algo escrito en `REPORTING_EXPORT_PATH`.
     *
     * La otra mitad de la limpieza de huerfanos: cruzado con los `uuid` de las
     * filas que **si** tienen derecho a un fichero, la diferencia es basura de
     * generaciones que murieron sin cerrarse. Es la red de debajo de
     * {@see self::deleteAllFor()}, para el caso en que ni siquiera se llegara a
     * ejecutar el `failed()` del trabajo.
     *
     * @return list<string>
     */
    public function storedUuids(): array;
}
