<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use RuntimeException;

/**
 * Todos los huecos del tope de PDF estan ocupados y no se ha liberado ninguno
 * en la espera ({@see PdfRenderingSlots}).
 *
 * Es capacidad, no un fallo: el borde lo sirve como `503`
 * `pdf-rendering-busy` con `Retry-After`, y no se informa a `error_events`.
 * El mensaje no lleva datos de nadie (regla dura 21).
 */
final class PdfRenderingSlotsBusy extends RuntimeException
{
    private function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public static function allSlotsTaken(int $retryAfterSeconds): self
    {
        return new self(
            'El motor de PDF no tiene ahora ningun hueco libre. Vuelve a intentarlo en unos segundos.',
            max(1, $retryAfterSeconds),
        );
    }
}
