<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Exception;

use RuntimeException;

/**
 * El rango de la reconciliacion no describe ninguna ventana de jornadas.
 *
 * Es de `Application` y no de `Domain` porque no expresa ninguna regla del
 * negocio: expresa que la orden que llego no se puede ejecutar. Un rango al
 * reves no se corrige en silencio dandole la vuelta — quien escribio
 * `--from=2026-04-01 --to=2026-03-01` creeria haber revisado marzo entero.
 */
final class InvalidReconciliationRange extends RuntimeException
{
    public static function endsBeforeItStarts(string $fromIsoDate, string $toIsoDate): self
    {
        return new self('Reconciliation range ends on '.$toIsoDate.', before it starts on '.$fromIsoDate.'.');
    }
}
