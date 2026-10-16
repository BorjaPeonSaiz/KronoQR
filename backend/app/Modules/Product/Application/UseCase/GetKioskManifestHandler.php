<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Shared\Domain\ValueObject\LogoFormat;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use Throwable;

/**
 * Compone el manifiesto de aplicacion web del quiosco con la marca de la
 * instalacion (**RF-PD-08**, regla dura 13).
 *
 * ## Parte de la MISMA marca que `GET /api/v1/branding`
 *
 * No lee `installation_settings` ni el disco: pide {@see GetBrandingHandler}, que
 * ya aplica el plan (ADR-023), ya tolera una configuracion ilegible devolviendo
 * la marca del producto y ya trae el logotipo comprobado. Dos caminos hacia el
 * mismo dato serian dos marcas distintas en la misma tablet —la pantalla de
 * espera con la de un hotel y el icono instalado con la de otro—.
 *
 * ## Cuando el logotipo sirve de icono
 *
 * Solo si es un **PNG cuadrado de al menos 512 px de lado**. Es lo que Android
 * necesita para ofrecer «Instalar» y lo que hace que el navegador reduzca a 192
 * sin deformar. Un SVG, un PNG pequeño o uno apaisado dejan los iconos de serie,
 * con el `maskable` incluido: **aqui no se rasteriza ni se recorta nada**, porque
 * un icono deformado en la pantalla de inicio es peor que el del fabricante.
 *
 * **Una sola entrada, con su tamaño REAL.** El logotipo puede medir de 512 a
 * 2048 px de lado, y declararlo a 192 o a 512 cuando no mide eso hace que
 * Chromium avise de un manifiesto incoherente. El navegador ya reduce el de
 * 512 o mas al tamaño que necesite.
 *
 * Las dimensiones se leen de los bytes ya comprobados por `LogoFileInspector`
 * (que garantiza un IHDR legible y un tamaño acotado), sin volver al disco.
 *
 * ## Los iconos de serie van relativos al quiosco
 *
 * Donde vive el quiosco (`/kiosk/`) es comportamiento del quiosco y lo escribe
 * el borde HTTP, igual que `id`, `start_url` y `scope`. Aqui solo se nombran los
 * ficheros, tal como los declara `frontend-kiosk/vite.config.ts`.
 */
final readonly class GetKioskManifestHandler
{
    /**
     * El color de serie del quiosco: el de su pantalla de arranque, el mismo
     * que declara `frontend-kiosk/vite.config.ts`. Es del producto, no de un
     * cliente.
     */
    public const string PRODUCT_THEME_COLOR = '#0f172a';

    /** Lado minimo del logotipo para publicarlo como icono. */
    public const int MINIMUM_ICON_SIDE = 512;

    /**
     * Los iconos de serie, relativos al quiosco, como en `vite.config.ts`.
     *
     * @var list<array{string, string, string}>
     */
    private const array PRODUCT_ICONS = [
        ['icons/icon-192.png', '192x192', 'any'],
        ['icons/icon-512.png', '512x512', 'any'],
        ['icons/icon-maskable-512.png', '512x512', 'maskable'],
    ];

    public function __construct(private GetBrandingHandler $branding) {}

    public function handle(): KioskManifest
    {
        $branding = $this->branding->handle();
        $logoUrl = $branding->logoUrl();
        $logoSizes = self::iconSizes($branding->logo);
        $brandIcon = $logoUrl !== null && $logoSizes !== null
            ? KioskManifestIcon::absolute($logoUrl, $logoSizes, 'any')
            : null;

        return new KioskManifest(
            applicationName: $branding->applicationName,
            locale: $branding->locales->default,
            themeColor: $branding->accentColor ?? self::PRODUCT_THEME_COLOR,
            customThemeColor: $branding->accentColor !== null,
            icons: $brandIcon instanceof KioskManifestIcon ? [$brandIcon] : self::productIcons(),
            brandedIcons: $brandIcon instanceof KioskManifestIcon,
        );
    }

    /**
     * El tamaño real del logotipo en `ANCHOxALTO` si sirve de icono —PNG
     * cuadrado de al menos 512 px—, o `null`.
     */
    private static function iconSizes(?LogoImage $logo): ?string
    {
        if (! $logo instanceof LogoImage || $logo->format !== LogoFormat::PNG) {
            return null;
        }

        try {
            $size = getimagesizefromstring($logo->bytes);
        } catch (Throwable) {
            // Un aviso de PHP convertido en excepcion por el framework. El
            // manifiesto no puede fallar por una imagen: iconos de serie.
            return null;
        }

        if ($size === false) {
            return null;
        }

        [$width, $height] = $size;

        return $width === $height && $width >= self::MINIMUM_ICON_SIDE
            ? $width.'x'.$height
            : null;
    }

    /**
     * @return list<KioskManifestIcon>
     */
    private static function productIcons(): array
    {
        return array_map(
            static fn (array $icon): KioskManifestIcon => KioskManifestIcon::kioskAsset(...$icon),
            self::PRODUCT_ICONS,
        );
    }
}
