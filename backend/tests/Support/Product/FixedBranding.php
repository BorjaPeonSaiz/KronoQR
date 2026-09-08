<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\Branding;

/**
 * Una marca fija, sin base de datos (RF-PD-08, tarea 5.8).
 *
 * ## Para que existe
 *
 * Hay pruebas que comprueban **la forma de un documento** —los bytes del CSV de
 * la Inspeccion, la geometria del QR en la tarjeta— y no la marca. Esas no deben
 * depender de que haya una fila en `installation_settings`: si lo hicieran,
 * cambiar el nombre de serie del producto rompería una prueba de dialecto CSV y
 * nadie entenderia por que.
 *
 * El doble del logotipo es {@see FixedLogo}, y esta aparte porque los dos puertos
 * declaran `current()` con tipos de retorno distintos: una sola clase no puede
 * implementar los dos.
 */
final readonly class FixedBranding implements BrandingProvider
{
    public function __construct(private Branding $branding) {}

    /**
     * La marca del producto, que es el valor de serie del catalogo.
     *
     * Atajo para las pruebas a las que la marca les da igual y solo necesitan
     * **una**. Los tres valores son los de `SettingKey`; si alguno cambiara ahi,
     * `SettingCatalogTest` sigue siendo quien lo vigila.
     */
    public static function product(): self
    {
        return new self(new Branding(
            applicationName: 'KronoQR',
            logoPath: null,
            accentColor: '#b8542a',
        ));
    }

    public function current(): Branding
    {
        return $this->branding;
    }
}
