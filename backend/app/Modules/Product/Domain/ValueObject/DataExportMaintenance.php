<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Lo que hizo la pasada de mantenimiento horaria (**RF-PD-14**, RL-20, ADR-045).
 *
 * ## Cuatro cifras y no una
 *
 * Son cuatro hechos distintos y le dicen cosas distintas a quien administra el
 * servidor:
 *
 * - `purged` es la rutina: un fichero vencido menos en el disco. Lo normal es
 *   cero.
 * - `released` es un aviso: **habia una exportacion atascada** —un trabajador de
 *   cola que murio, un `docker compose down` a mitad— que estaba bloqueando la
 *   siguiente. Si se repite cada hora, lo que hay que mirar es la cola.
 * - `orphans` son restos sin fila viva que se han borrado: un ZIP que sobrevivio
 *   a una restauracion, un `.work-<uuid>/` de una generacion interrumpida. Si
 *   deja de ser cero un dia si y otro tambien, algo mata los trabajos.
 * - `missing` es **un evento de seguridad**: un ZIP que desaparecio antes de
 *   caducar. Cada uno deja `data_export.file_missing` en `audit_log`.
 *
 * Devolver solo la suma dejaria «he borrado un fichero caducado» y «alguien se
 * ha llevado una copia completa de la plantilla» indistinguibles en la salida
 * del comando.
 */
final readonly class DataExportMaintenance
{
    public function __construct(
        /** Exportaciones cuyo fichero se ha borrado por caducidad. La fila queda. */
        public int $purged,
        /** Exportaciones atascadas declaradas `failed` con motivo `stale`. */
        public int $released,
        /** Restos sin fila viva borrados: ZIP, `.work-<uuid>/` y temporales de `ZipArchive`. */
        public int $orphans = 0,
        /** Filas cuyo ZIP desaparecio antes de su `expires_at`. */
        public int $missing = 0,
    ) {}

    public function didNothing(): bool
    {
        return $this->purged === 0 && $this->released === 0 && $this->orphans === 0 && $this->missing === 0;
    }
}
