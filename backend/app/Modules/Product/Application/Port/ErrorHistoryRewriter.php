<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ErrorEvent;

/**
 * La tabla `error_events`, vista desde **el unico caso de uso que reescribe
 * filas**: el saneado de las filas anteriores a la 2.2.0 (ADR-048, H6).
 *
 * ## Por que es un puerto aparte
 *
 * `ErrorEventRepository` declara que nadie edita un error, y es cierto para todo
 * el producto salvo para esta operacion de mantenimiento, que corre una vez al
 * actualizar y la lanza una migracion de datos. Separarla deja escrito que es
 * una excepcion y quien la usa, en vez de anadir dos metodos de escritura a la
 * interfaz que usa el resto del modulo.
 */
interface ErrorHistoryRewriter
{
    /**
     * Los grupos con `id` mayor que `$afterId`, por `id` ascendente, como mucho
     * `$limit`. Recorrer por clave y no por pagina: lo que se fusiona por el
     * camino no desplaza a lo que queda por leer.
     *
     * @return list<ErrorEvent>
     */
    public function groupsAfter(int $afterId, int $limit): array;

    /** El grupo que tiene esa huella, si lo hay. */
    public function findByFingerprint(string $fingerprint): ?ErrorEvent;

    /**
     * Sobrescribe el grupo `$group->id` con su huella, su mensaje, su contexto y
     * sus columnas de texto (`code`, `exception_class`, `file`, `app_version`).
     * No toca recuentos, instantes ni resolucion.
     *
     * @return bool `false` si la huella nueva la ha tomado otro grupo entre la
     *              busqueda y la escritura (el sumidero no se detiene mientras
     *              corre la migracion): quien llama los funde. Nunca lanza por eso.
     */
    public function rewrite(ErrorEvent $group): bool;

    /**
     * Funde el grupo `$absorbedId` en `$survivorId` con
     * {@see ErrorEvent::absorb()} y lo borra, en una sola transaccion y con las
     * dos filas bloqueadas: una aparicion que llegue a la vez por el sumidero
     * no puede perderse.
     */
    public function merge(int $survivorId, int $absorbedId): void;
}
