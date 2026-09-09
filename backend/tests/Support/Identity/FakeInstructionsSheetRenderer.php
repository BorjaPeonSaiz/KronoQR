<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Shared\Domain\ValueObject\Branding;

/**
 * La hoja de instrucciones, sin Chromium.
 *
 * **Por que existe**, y es el mismo motivo que {@see FakeCardRenderer}: el
 * adaptador de produccion arranca un navegador por proceso externo. Una prueba
 * de feature que lo usara mediria dos cosas a la vez —la autorizacion y la
 * resolucion del idioma por un lado, la presencia de un binario en la maquina
 * por otro— y fallaria de forma intermitente en cuanto el runner de la CI no lo
 * tuviera. Que la hoja quepa **de verdad en una cara** lo comprueba
 * `Tests\Integration\Identity\InstructionsSheetLayoutTest`, que si arranca
 * Chromium y cuenta las paginas.
 *
 * **Guarda con que se le pidio dibujar**: el idioma, la marca y la direccion del
 * portal son las tres decisiones del caso de uso, y son justo lo que una prueba
 * de feature tiene que poder afirmar sin abrir un PDF.
 */
final class FakeInstructionsSheetRenderer implements InstructionsSheetRenderer
{
    /**
     * Cabecera de un PDF real. Importa: el controlador anuncia
     * `Content-Type: application/pdf`, y un cuerpo que no lo pareciera dejaria
     * pasar una respuesta mal etiquetada.
     */
    public const string PDF_BYTES = "%PDF-1.7\n% hoja de instrucciones de prueba\n%%EOF\n";

    /** @var list<string> */
    public array $locales = [];

    /** @var list<Branding> */
    public array $brands = [];

    /** @var list<string> */
    public array $portalUrls = [];

    public function render(string $locale, Branding $brand, string $portalUrl): string
    {
        $this->locales[] = $locale;
        $this->brands[] = $brand;
        $this->portalUrls[] = $portalUrl;

        return self::PDF_BYTES;
    }

    public function lastLocale(): ?string
    {
        return $this->locales === [] ? null : $this->locales[\count($this->locales) - 1];
    }

    public function lastPortalUrl(): ?string
    {
        return $this->portalUrls === [] ? null : $this->portalUrls[\count($this->portalUrls) - 1];
    }

    public function renders(): int
    {
        return \count($this->locales);
    }
}
