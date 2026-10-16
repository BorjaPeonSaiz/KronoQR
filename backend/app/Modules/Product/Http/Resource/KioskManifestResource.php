<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Application\UseCase\KioskManifest;
use App\Modules\Product\Application\UseCase\KioskManifestIcon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el esquema `WebAppManifest` (RF-PD-08): el manifiesto W3C del
 * quiosco con la marca de la instalacion.
 *
 * Lo que es comportamiento del quiosco —`id`, `start_url`, `scope`, `display`,
 * `display_override`, `orientation`, `background_color`— se escribe aqui y no
 * sale de ningun ajuste: son los mismos valores que `frontend-kiosk/vite.config.ts`
 * y el contrato los fija. **`id` no cambia nunca con la marca**: si cambiara, la
 * tablet trataria el quiosco rebautizado como otra aplicacion.
 *
 * La descripcion es un texto de usuario y va en i18n, en el idioma por defecto
 * de la instalacion, que es el que declara `lang`.
 *
 * @property-read KioskManifest $resource
 */
final class KioskManifestResource extends JsonResource
{
    public const string CONTENT_TYPE = 'application/manifest+json';

    private const string KIOSK_PATH = '/kiosk/';

    private const string BACKGROUND_COLOR = '#0f172a';

    public static $wrap = null;

    public function __construct(KioskManifest $manifest)
    {
        parent::__construct($manifest);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KioskManifest $manifest */
        $manifest = $this->resource;

        return [
            'id' => self::KIOSK_PATH,
            'name' => $manifest->applicationName,
            'short_name' => $manifest->applicationName,
            'description' => __('kiosk.manifest.description', [], $manifest->locale),
            'lang' => $manifest->locale,
            'start_url' => self::KIOSK_PATH,
            'scope' => self::KIOSK_PATH,
            'display' => 'fullscreen',
            'display_override' => ['fullscreen', 'standalone'],
            'orientation' => 'landscape',
            'theme_color' => $manifest->themeColor,
            'background_color' => self::BACKGROUND_COLOR,
            'icons' => array_map(
                static fn (KioskManifestIcon $icon): array => [
                    'src' => $icon->src,
                    'sizes' => $icon->sizes,
                    'type' => 'image/png',
                    'purpose' => $icon->purpose,
                ],
                $manifest->icons,
            ),
        ];
    }
}
