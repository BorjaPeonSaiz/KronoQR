<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Domain\ValueObject\LogoFormat;
use App\Modules\Shared\Domain\ValueObject\LogoImage;

/**
 * Un logotipo fijo —o ninguno— sin tocar el disco (RF-PD-08, tarea 5.8).
 *
 * Compañero de {@see FixedBranding}, y separado de el porque los dos puertos
 * declaran `current()` con tipos de retorno distintos.
 *
 * **`none()` es el caso por defecto de una instalacion**, y por eso tiene nombre
 * propio: la mayoria de las pruebas de documentos quieren afirmar sobre el
 * documento sin logotipo, que es lo que ve casi todo el mundo.
 */
final readonly class FixedLogo implements BrandingLogoReader
{
    public function __construct(private ?LogoImage $logo = null) {}

    /** Sin logotipo: lo que devuelve una instalacion que no ha configurado ninguno. */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Un PNG de 1x1 de verdad.
     *
     * Bytes reales y no una cadena cualquiera: lo que se afirma sobre el
     * documento es que lleva una URI de datos con `image/png`, y con bytes
     * inventados la prueba pasaria igual mientras el navegador dibujaria un
     * hueco.
     */
    public static function png(): self
    {
        return new self(new LogoImage(LogoFormat::PNG, self::onePixelPng()));
    }

    /** Los bytes de un PNG de 1x1, para quien necesite el fichero y no el doble. */
    public static function onePixelPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }

    public function current(): ?LogoImage
    {
        return $this->logo;
    }
}
