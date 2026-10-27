<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use RuntimeException;
use Throwable;

/**
 * El tope de PDF vive en Redis y Redis no responde
 * ({@see PdfRenderingSlots}). Falla
 * cerrado: sin el tope no se arranca ningun Chromium, porque abrirlo seria
 * quitar la proteccion de memoria justo cuando la instalacion esta peor.
 *
 * El borde lo sirve como `503` `service-unavailable`. El mensaje habla de la
 * instalacion, nunca de la persona (regla dura 21).
 */
final class PdfRenderingSlotsUnavailable extends RuntimeException
{
    public static function limiterFailed(Throwable $cause): self
    {
        return new self(
            'El motor de composicion de PDF no tiene turnos porque Redis no responde. '
            .'Comprueba Redis en la instalacion.',
            previous: $cause,
        );
    }
}
