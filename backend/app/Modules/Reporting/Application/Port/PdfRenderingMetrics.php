<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * `reporting_pdf_rendering_rejected_total{reason}` (doc 02 §8.2): PDF que el
 * tope de composiciones simultaneas no dejo componer.
 *
 * - `busy`: todos los huecos ocupados tras la espera; quien lo pidio recibio
 *   `503` con `Retry-After` y la salida en CSV.
 * - `limiter`: Redis no respondio para repartir los huecos y se fallo cerrado.
 *
 * Un `busy` sostenido es la senal de que `REPORTING_PDF_MAX_CONCURRENT` se ha
 * quedado corto para la plantilla, o de que alguien esta pidiendo PDF en bucle.
 * Ninguna etiqueta lleva datos de nadie (regla dura 21).
 */
interface PdfRenderingMetrics
{
    public const string REASON_BUSY = 'busy';

    public const string REASON_LIMITER = 'limiter';

    public function rejected(string $reason): void;
}
