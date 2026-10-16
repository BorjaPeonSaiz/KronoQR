<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

/**
 * Un icono del manifiesto del quiosco (RF-PD-08, esquema `WebAppManifestIcon`).
 *
 * Siempre PNG: es lo unico que Android acepta para ofrecer «Instalar», y por eso
 * el contrato fija `type: image/png` y este objeto no lo guarda.
 *
 * ## Dos clases de ruta, y quien pone el prefijo
 *
 * - {@see self::kioskAsset()}: un fichero de la PWA, **relativo al quiosco**
 *   (`icons/icon-192.png`). Donde vive el quiosco es comportamiento del quiosco
 *   y le pone delante su ruta el borde HTTP, el mismo que escribe `scope`.
 * - {@see self::absolute()}: una ruta ya absoluta en el mismo origen, la del
 *   logotipo de la marca (`/api/v1/branding/logo?v=...`), que no depende de
 *   donde este montado el quiosco.
 */
final readonly class KioskManifestIcon
{
    private function __construct(
        /** Relativa al quiosco si `kioskAsset`, absoluta en el origen si no. */
        public string $src,
        /** `ANCHOxALTO`, el tamaño real del fichero. */
        public string $sizes,
        /** `any` o `maskable`. */
        public string $purpose,
        /** Si `src` es relativa al quiosco y hay que anteponerle su ruta. */
        public bool $kioskAsset,
    ) {}

    public static function kioskAsset(string $path, string $sizes, string $purpose): self
    {
        return new self($path, $sizes, $purpose, true);
    }

    public static function absolute(string $url, string $sizes, string $purpose): self
    {
        return new self($url, $sizes, $purpose, false);
    }
}
