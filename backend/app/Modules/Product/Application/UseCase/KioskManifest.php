<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

/**
 * Lo que cambia con la marca en el manifiesto del quiosco (RF-PD-08, esquema
 * `WebAppManifest`).
 *
 * Solo las partes que dependen de la instalacion. Lo que es comportamiento del
 * quiosco —`id`, `start_url`, `display`, `background_color`— no
 * viaja aqui: lo fija el contrato y lo escribe el borde HTTP, y asi ningun
 * ajuste puede moverlo.
 */
final readonly class KioskManifest
{
    /**
     * @param  list<KioskManifestIcon>  $icons
     */
    public function __construct(
        /** El nombre de la marca, nunca vacio. Ni la licencia lo degrada. */
        public string $applicationName,
        /** El idioma por defecto de la instalacion. */
        public string $locale,
        /** `#rrggbb`: el fondo del quiosco (no el acento de la marca). */
        public string $themeColor,
        /** Si la instalacion tiene un acento de marca propio (telemetria; no afecta al manifiesto). */
        public bool $customThemeColor,
        public array $icons,
        /** Si los iconos son el logotipo de la marca y no los de serie. */
        public bool $brandedIcons,
    ) {}
}
