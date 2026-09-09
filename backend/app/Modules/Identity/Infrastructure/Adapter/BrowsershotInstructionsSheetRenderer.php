<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Domain\ValueObject\Branding;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * La hoja de instrucciones del empleado, con `spatie/laravel-pdf` sobre
 * Browsershot (doc 02 §3.1, tarea 5.11b, **RL-05**).
 *
 * Mismo motor y mismas decisiones que {@see BrowsershotCardRenderer}, con las
 * diferencias que impone el documento:
 *
 * ## A4 vertical y **una sola cara**
 *
 * La hoja se entrega en la mano junto con la tarjeta y el PIN, en un unico acto
 * presencial (ficha 5.11b, decision 1): dos caras significan que la mitad de las
 * veces se lee solo una. Los margenes son de 14 mm —lo que cualquier impresora
 * de oficina imprime sin recortar— y el contenido esta dimensionado para caber
 * con holgura; que quepa de verdad lo comprueba
 * `Tests\Integration\Identity\InstructionsSheetLayoutTest` contando las paginas
 * del PDF de verdad, en los dos idiomas.
 *
 * ## Sin red
 *
 * Ni tipografia remota, ni hoja de estilos externa, ni imagen por URL: el
 * producto se instala en servidores sin salida a internet (ADR-016). Los
 * pictogramas son SVG **escritos en la plantilla** y el logotipo llega en
 * base64. La unica direccion que aparece en el documento es la del portal del
 * propio cliente, y es texto impreso, no un recurso que haya que ir a buscar.
 *
 * ## El idioma es un parametro, no el del proceso
 *
 * Las frases se resuelven con {@see Lang::get()} **contra el idioma que se
 * pide**, no con `__()`. Es la unica forma de que un mismo servidor imprima la
 * hoja en castellano y en ingles sin cambiar el idioma de la peticion que la
 * genera, que es lo que hace el panel cuando ofrece un boton por cada idioma
 * activo.
 *
 * `lang/{es,en}/instructions-sheet.php` es un array **plano** a proposito: lo
 * recorre tambien la prueba que ata cada frase a `docs/cliente/hoja-empleado.md`.
 * Aqui se lee entero de una vez y con los marcadores ya sustituidos
 * (`:app_name`, `:portal_url`).
 *
 * ## No lleva ningun dato personal
 *
 * Es la misma hoja para toda la plantilla, y por eso este renderizador no recibe
 * ni un titular ni una credencial. Si algun dia llevara un nombre, dejaria de
 * poder cachearse, de poder imprimirse por lotes y de ser este documento.
 */
final readonly class BrowsershotInstructionsSheetRenderer implements InstructionsSheetRenderer
{
    /**
     * Margen de pagina en milimetros.
     *
     * Los 8 mm de la hoja de tarjetas son el minimo para no recortar; aqui hay
     * texto que se lee de pie, y un texto pegado al borde se lee mal aunque se
     * imprima entero.
     */
    private const float MARGIN_MM = 14.0;

    public function __construct(private BrandingLogoReader $logos) {}

    public function render(string $locale, Branding $brand, string $portalUrl): string
    {
        // `dontCache()` lo primero, como en las tarjetas: `laravel-pdf` sabe
        // cachear el contenido generado indexado por la huella del HTML, y aqui
        // esa cache serviria la marca y la direccion de ayer — que es justo lo
        // que el `Cache-Control: no-store` del endpoint existe para evitar.
        return (new PdfBuilder)
            ->dontCache()
            ->format(Format::A4)
            ->margins(self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM)
            ->html($this->htmlFor($locale, $brand, $portalUrl))
            ->generatePdfContent();
    }

    /**
     * El documento que se le entrega a Chromium.
     *
     * **Publico a proposito**, como el equivalente de las tarjetas: las pruebas
     * afirman sobre lo que se imprime —la direccion del portal, la marca, las
     * frases del idioma pedido, la ausencia de cualquier recurso remoto— y un PDF
     * no se puede interrogar sobre eso (Chromium incrusta la tipografia en
     * subconjunto y el texto no aparece en los bytes). No forma parte del puerto:
     * quien llama desde la aplicacion solo conoce `render()`.
     */
    public function htmlFor(string $locale, Branding $brand, string $portalUrl): string
    {
        $logo = $this->logos->current();

        return View::make('pdf.instructions-sheet', [
            'locale' => $locale,
            'texts' => $this->texts($locale, $brand, $portalUrl),
            'portalUrl' => $portalUrl,
            'brand' => [
                'name' => $brand->applicationName,
                'logo' => $logo instanceof LogoImage ? $logo->dataUri() : null,
                'accent' => $brand->accentColor,
            ],
        ])->render();
    }

    /**
     * Las frases del idioma pedido, con los marcadores ya sustituidos.
     *
     * Se lee el grupo entero de una vez —`Lang::get()` sustituye tambien dentro
     * de un array— en lugar de clave a clave: la plantilla nombra las claves que
     * dibuja y este metodo no tiene por que conocerlas. Lo que no llegue como
     * texto se descarta: un valor anidado seria una frase que la plantilla no
     * sabe dibujar y una guia que no sabe reproducir.
     *
     * @return array<string, string>
     */
    private function texts(string $locale, Branding $brand, string $portalUrl): array
    {
        $lines = Lang::get(
            'instructions-sheet',
            ['app_name' => $brand->applicationName, 'portal_url' => $portalUrl],
            $locale,
        );

        if (! \is_array($lines)) {
            return [];
        }

        $texts = [];

        foreach ($lines as $key => $line) {
            if (\is_string($key) && \is_string($line)) {
                $texts[$key] = $line;
            }
        }

        return $texts;
    }
}
