<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Shared\Domain\ValueObject\Branding;
use App\Modules\Shared\Domain\ValueObject\LocalePolicy;
use App\Modules\Shared\Domain\ValueObject\LogoImage;

/**
 * La marca de la instalacion tal como la reciben las tres aplicaciones
 * (RF-PD-08, esquema `Branding` del contrato).
 *
 * ## No es {@see Branding}, y la
 * diferencia es el motivo de que existan las dos
 *
 * Aquel es lo que consume **quien dibuja un documento**: nombre, ruta del
 * logotipo y color, siempre presentes, con el valor de serie ya aplicado. Este
 * es lo que consume **quien pinta una interfaz**, y necesita dos cosas que aquel
 * no tiene por que saber:
 *
 * - **Si el color es el de serie o uno elegido.** Con `null`, las SPA no tocan
 *   ningun token `--kq-*` y el sistema visual del producto queda intacto (doc 06
 *   §7); con un color, derivan tonos. Una tarjeta impresa no tiene esa duda:
 *   siempre pinta el filete con el color que le den.
 * - **Los idiomas de la instalacion**, que no son marca pero viajan en la misma
 *   respuesta porque el selector del quiosco los necesita antes de identificar a
 *   nadie, igual que el logotipo.
 *
 * Y el logotipo va **ya leido**, no como ruta: quien recibe esto es el borde
 * HTTP y la ruta del fichero en el servidor del cliente no sale de la
 * aplicacion.
 */
final readonly class InstallationBranding
{
    public function __construct(
        /** Nunca vacio: sin marca configurada es el nombre del producto. */
        public string $applicationName,
        /** El color elegido en `#rrggbb`, o `null` si rige el del producto. */
        public ?string $accentColor,
        /** El logotipo utilizable, o `null`. */
        public ?LogoImage $logo,
        public LocalePolicy $locales,
    ) {}

    /**
     * URL relativa del logotipo con la huella del contenido, o `null`.
     *
     * **La huella la pone quien sabe el contenido**, y por eso se construye aqui
     * y no en el borde: es lo que hace que `Cache-Control: immutable` sea seguro
     * —cambiar la imagen cambia la URL— y lo que le dice al service worker del
     * quiosco que hay un logotipo nuevo que descargar (RF-KI-03).
     */
    public function logoUrl(): ?string
    {
        return $this->logo instanceof LogoImage
            ? '/api/v1/branding/logo?v='.$this->logo->cacheTag()
            : null;
    }
}
