<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Application\UseCase\GetBrandingHandler;
use App\Modules\Product\Application\UseCase\InstallationBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el esquema `Branding` (RF-PD-08, tarea 5.8).
 *
 * **Envuelve un objeto de valor, nunca un modelo**, como el resto de recursos
 * del modulo: asi no hay ninguna via por la que una columna nueva de
 * `installation_settings` aparezca en una respuesta **publica** sin que nadie lo
 * decida. Quien elige las cinco claves que salen es
 * {@see GetBrandingHandler}, y lo hace
 * nombrandolas una a una.
 *
 * Aqui no queda ninguna decision: los cuatro campos del contrato, en su forma
 * `snake_case`, y nada mas.
 *
 * @property-read InstallationBranding $resource
 */
final class BrandingResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(InstallationBranding $branding)
    {
        parent::__construct($branding);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InstallationBranding $branding */
        $branding = $this->resource;

        return [
            'application_name' => $branding->applicationName,
            'accent_color' => $branding->accentColor,
            'logo_url' => $branding->logoUrl(),
            'locales' => [
                'default' => $branding->locales->default,
                'available' => $branding->locales->available,
            ],
        ];
    }
}
