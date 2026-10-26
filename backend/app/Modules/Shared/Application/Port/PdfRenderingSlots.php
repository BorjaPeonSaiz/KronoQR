<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Application\Exception\PdfRenderingSlotsBusy;
use App\Modules\Shared\Application\Exception\PdfRenderingSlotsUnavailable;
use Closure;

/**
 * El tope de composiciones de PDF simultaneas de TODA la instalacion
 * (`reporting.pdf.max_concurrent`). Cada composicion es un
 * Chromium, y un Chromium llega a cientos de MiB: el tope protege la memoria
 * del servidor, y por eso es uno solo para todo lo que arranca un Chromium
 * —informes, registro propio, tarjetas y hoja de instrucciones—, no uno por
 * modulo. Dos topes de 2 dejarian correr 4.
 *
 * Vive en `Shared` porque lo usan `Reporting` e `Identity`, que no pueden
 * importarse entre si.
 */
interface PdfRenderingSlots
{
    /**
     * Ejecuta `$render` con un hueco tomado, y lo suelta al terminar, tambien
     * si `$render` falla.
     *
     * @param  Closure(): string  $render  La composicion; devuelve los bytes del PDF.
     * @param  int  $waitSeconds  Lo que se espera un hueco antes de rendirse.
     *
     * @throws PdfRenderingSlotsBusy cuando no se libera ningun hueco en `$waitSeconds`
     * @throws PdfRenderingSlotsUnavailable cuando el reparto de huecos no responde (falla cerrado)
     */
    public function compose(Closure $render, int $waitSeconds): string;
}
