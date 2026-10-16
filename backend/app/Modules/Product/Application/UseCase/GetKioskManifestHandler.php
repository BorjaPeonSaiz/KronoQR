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
 * Las dimensiones se leen de los bytes ya comprobados por `LogoFileInspector`
 * (que garantiza un IHDR legible y un tamaño acotado), sin volver al disco.
 */
final readonly class GetKioskManifestHandler
{
    /**
     * El color de serie del quiosco: el de su pantalla de arranque, el mismo
     * que declara `frontend-kiosk/vite.config.ts`. Es del producto, no de un
     * cliente.
     */
    public const string PRODUCT_THEME_COLOR = '#0f172a';

    /** Lado minimo del logotipo para publicarlo como icono de 512. */
    public const int MINIMUM_ICON_SIDE = 512;

    /** Los iconos de serie, los que declara `frontend-kiosk/vite.config.ts`. */
    private const array PRODUCT_ICONS = [
        ['/kiosk/icons/icon-192.png', '192x192', 'any'],
        ['/kiosk/icons/icon-512.png', '512x512', 'any'],
        ['/kiosk/icons/icon-maskable-512.png', '512x512', 'maskable'],
    ];

    public function __construct(private GetBrandingHandler $branding) {}

    public function handle(): KioskManifest
    {
        $branding = $this->branding->handle();
        $logoUrl = $branding->logoUrl();
        $brandIcons = $logoUrl !== null && self::servesAsIcon($branding->logo)
            ? self::brandIcons($logoUrl)
            : null;

        return new KioskManifest(
            applicationName: $branding->applicationName,
            locale: $branding->locales->default,
            themeColor: $branding->accentColor ?? self::PRODUCT_THEME_COLOR,
            customThemeColor: $branding->accentColor !== null,
            icons: $brandIcons ?? self::productIcons(),
            brandedIcons: $brandIcons !== null,
        );
    }

    /**
     * Si el logotipo es un PNG cuadrado de al menos 512 px.
     */
    private static function servesAsIcon(?LogoImage $logo): bool
    {
        if (! $logo instanceof LogoImage || $logo->format !== LogoFormat::PNG) {
            return false;
        }

        try {
            $size = getimagesizefromstring($logo->bytes);
        } catch (Throwable) {
            // Un aviso de PHP convertido en excepcion por el framework. El
            // manifiesto no puede fallar por una imagen: iconos de serie.
            return false;
        }

        if ($size === false) {
            return false;
        }

        [$width, $height] = $size;

        return $width === $height && $width >= self::MINIMUM_ICON_SIDE;
    }

    /**
     * @return list<KioskManifestIcon>
     */
    private static function brandIcons(string $logoUrl): array
    {
        return [
            new KioskManifestIcon($logoUrl, '192x192', 'any'),
            new KioskManifestIcon($logoUrl, '512x512', 'any'),
        ];
    }

    /**
     * @return list<KioskManifestIcon>
     */
    private static function productIcons(): array
    {
        return array_map(
            static fn (array $icon): KioskManifestIcon => new KioskManifestIcon(...$icon),
            self::PRODUCT_ICONS,
        );
    }
}
