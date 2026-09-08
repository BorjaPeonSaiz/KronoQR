<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Lo que hizo la pasada de mantenimiento horaria (**RF-PD-14**, RL-20).
 *
 * ## Dos cifras y no una
 *
 * Purgar y desatascar son dos hechos distintos y le dicen cosas distintas a quien
 * administra el servidor:
 *
 * - `purged` es la rutina: un fichero vencido menos en el disco. Lo normal es
 *   cero.
 * - `released` es un aviso: **habia una exportacion atascada** —un trabajador de
 *   cola que murio, un `docker compose down` a mitad— que estaba bloqueando la
 *   siguiente. Nada esta roto y no hay que hacer nada, pero si eso se repite cada
 *   hora, lo que hay que mirar es la cola.
 *
 * Devolver solo la suma dejaria «he borrado un fichero caducado» y «he
 * desbloqueado el producto» indistinguibles en la salida del comando.
 */
final readonly class DataExportMaintenance
{
    public function __construct(
        /** Exportaciones cuyo fichero se ha borrado por caducidad. La fila queda. */
        public int $purged,
        /** Exportaciones atascadas declaradas `failed` con motivo `stale`. */
        public int $released,
    ) {}

    public function didNothing(): bool
    {
        return $this->purged === 0 && $this->released === 0;
    }
}
