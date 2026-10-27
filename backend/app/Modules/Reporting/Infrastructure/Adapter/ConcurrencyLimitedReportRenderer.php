<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Exception\ReportRenderingBusy;
use App\Modules\Reporting\Application\Exception\ReportRenderingUnavailable;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsBusy;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsUnavailable;

/**
 * Tope de composiciones de PDF simultaneas, delante del motor
 * ({@see BrowsershotReportRenderer}). El tope es el de toda la instalacion
 * ({@see PdfRenderingSlots}, ligado en `SharedServiceProvider`): las tarjetas y
 * la hoja de instrucciones de `Identity` reciben el mismo puerto y pasan por el
 * mismo embudo, con una sola configuracion.
 *
 * - **Saturado** tras esperar `$waitSeconds`: {@see ReportRenderingBusy}, que el
 *   borde sirve como `503` con `Retry-After` y la salida en CSV.
 * - **Sin Redis**: {@see ReportRenderingUnavailable}, el mismo `503` que sin
 *   Chromium. Falla cerrado porque abrirlo seria quitar el tope justo cuando la
 *   instalacion esta peor; el CSV no depende de ninguno de los dos.
 *
 * Lo propio de `Reporting` es solo la traduccion: sus excepciones llevan en el
 * mensaje la salida en CSV, que una tarjeta no tiene.
 */
final readonly class ConcurrencyLimitedReportRenderer implements ReportDocumentRenderer
{
    public function __construct(
        private ReportDocumentRenderer $engine,
        private PdfRenderingSlots $slots,
        private int $waitSeconds,
    ) {}

    public function renderPdf(string $html, string $footerHtml, bool $alwaysTagged = false): string
    {
        try {
            return $this->slots->compose(
                fn (): string => $this->engine->renderPdf($html, $footerHtml, $alwaysTagged),
                $this->waitSeconds,
            );
        } catch (PdfRenderingSlotsBusy $busy) {
            throw ReportRenderingBusy::allSlotsTaken($busy->retryAfterSeconds);
        } catch (PdfRenderingSlotsUnavailable $unavailable) {
            throw ReportRenderingUnavailable::limiterFailed($unavailable->getPrevious() ?? $unavailable);
        }
    }
}
