<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

/**
 * Un icono del manifiesto del quiosco (RF-PD-08, esquema `WebAppManifestIcon`).
 *
 * Siempre PNG: es lo unico que Android acepta para ofrecer «Instalar», y por eso
 * el contrato fija `type: image/png` y este objeto no lo guarda.
 */
final readonly class KioskManifestIcon
{
    public function __construct(
        /** Ruta absoluta en el mismo origen. */
        public string $src,
        /** `192x192` o `512x512`. */
        public string $sizes,
        /** `any` o `maskable`. */
        public string $purpose,
    ) {}
}
