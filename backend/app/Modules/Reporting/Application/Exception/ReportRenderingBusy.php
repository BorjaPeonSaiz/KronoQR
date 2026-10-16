<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Exception;

use RuntimeException;

/**
 * Todas las composiciones de PDF simultaneas estan ocupadas y no se libero
 * ninguna en el tiempo de espera (`reporting.pdf.max_concurrent`).
 *
 * Hermana de {@see ReportRenderingUnavailable} y distinta de ella: el motor
 * esta y funciona, solo que ahora no cabe otro Chromium. El borde responde
 * `503` con `Retry-After` y la salida escrita —el mismo documento en CSV, que
 * no pasa por el motor (RL-05)—, con su propio `type` para que el cliente
 * sepa que reintentar tiene sentido.
 *
 * No es un fallo del producto: no se informa a `error_events`.
 */
final class ReportRenderingBusy extends RuntimeException
{
    private function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public static function allSlotsTaken(int $retryAfterSeconds): self
    {
        return new self(
            'El motor de PDF no tiene ahora ningun hueco libre. '
            .'Vuelve en unos segundos o descarga el mismo documento en CSV, que no pasa por el motor de PDF.',
            max(1, $retryAfterSeconds),
        );
    }
}
