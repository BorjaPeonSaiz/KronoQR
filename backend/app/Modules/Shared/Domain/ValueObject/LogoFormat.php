<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Los formatos de logotipo que el producto acepta (RF-PD-08, tarea 5.8).
 *
 * **Dos, y cerrado.** PNG porque es lo que sale de cualquier herramienta de
 * diseno con transparencia, y SVG porque un logotipo vectorial se imprime igual
 * de bien en una tarjeta de 85,6 mm que en la cabecera de un A4. No hay JPEG —un
 * logotipo sobre fondo blanco con artefactos de compresion se ve mal justo en la
 * tarjeta, que es donde mas se mira— ni GIF ni ICO.
 *
 * **El formato lo decide el CONTENIDO, nunca la extension.** Un `.png` que
 * dentro es un HTML servido con `Content-Type: image/png` es exactamente el
 * fallo que `X-Content-Type-Options: nosniff` no puede arreglar solo. Quien lo
 * determina es `LogoFileInspector`; este enum solo nombra el resultado.
 */
enum LogoFormat: string
{
    case PNG = 'png';

    case SVG = 'svg';

    /**
     * El tipo con el que viaja por HTTP y con el que se incrusta en un PDF.
     *
     * Vive aqui y no en el borde HTTP porque lo usan los dos: el endpoint
     * publico del logotipo y la URI de datos que se mete en la tarjeta impresa.
     * Con la cadena repartida, un dia el PDF diria `image/svg` y Chromium
     * dibujaria un hueco.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::PNG => 'image/png',
            self::SVG => 'image/svg+xml',
        };
    }
}
