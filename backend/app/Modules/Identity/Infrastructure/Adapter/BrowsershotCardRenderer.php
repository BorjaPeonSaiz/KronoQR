<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * El PDF de las tarjetas, con `spatie/laravel-pdf` sobre Browsershot (doc 02
 * §3.1, RF-QR-04).
 *
 * ## Las medidas son las de RF-QR-04 y salen del dominio
 *
 * ```
 * CardFormat::CARD    85,6 x 54 mm  — tarjeta de credito (ISO/IEC 7810 ID-1)
 * CardFormat::SHEET   A4, 10 por hoja — 2 columnas x 5 filas, con guias de corte
 * ```
 *
 * Las medidas no estan escritas en el CSS: llegan de {@see CardFormat}. Si
 * vivieran en la plantilla, cambiar el formato seria editar HTML y ninguna prueba
 * podria afirmar que la tarjeta mide lo que el requisito exige.
 *
 * ## Margenes a cero en el formato tarjeta
 *
 * Un PDF de 85,6 x 54 mm **es** la tarjeta: cualquier margen la encogeria y el
 * resultado ya no cabria en una funda estandar. El respiro interior lo pone el
 * `padding` de la plantilla, en milimetros.
 *
 * ## El PDF no se guarda en ningun sitio
 *
 * Se devuelven los bytes. No hay `save()`, no hay disco, no hay `storage/`: el
 * PDF de una tarjeta es un instrumento al portador y quien lo tenga puede fichar
 * por su dueño. Browsershot escribe un temporal suyo para hablar con Chromium y
 * lo borra al terminar; ese es el unico paso por disco y no esta bajo control de
 * esta clase.
 *
 * ## Sin red
 *
 * El HTML que se le entrega a Chromium no referencia ninguna URL: el QR va
 * incrustado como URI de datos SVG y el logotipo como base64. El producto se
 * instala en servidores sin salida a internet (ADR-016), y una hoja de estilos
 * remota convertiria la impresion de tarjetas en algo que depende de la red del
 * cliente.
 *
 * ## La marca es configuracion
 *
 * Nombre, logotipo y color de acento llegan de `config/branding.php` (RF-PD-08,
 * regla dura 13). Ninguno tiene valor de fabricante y todos pueden faltar: una
 * tarjeta sin logotipo se imprime igual. La tarea 5.8 sustituye la fuente por la
 * configuracion de la instalacion sin tocar esta clase ni la plantilla.
 */
final readonly class BrowsershotCardRenderer implements CardRenderer
{
    public function __construct(private EndroidQrEncoder $qr) {}

    public function render(array $cards, CardFormat $format): string
    {
        if ($cards === []) {
            // Nadie deberia llegar aqui —el caso de uso corta antes—, pero
            // arrancar un Chromium para un documento vacio es la clase de coste
            // que nadie revisa despues.
            return '';
        }

        // `generatePdfContent()` devuelve los bytes. `save()`, `disk()` y
        // `saveQueued()` existen en el paquete y NO se usan a proposito: ver el
        // docblock de la clase.
        return $this->builderFor($format)
            ->html($this->htmlFor($cards, $format))
            ->generatePdfContent();
    }

    /**
     * **`dontCache()` explicito, y es lo primero que hace.**
     *
     * `laravel-pdf` 2.12 sabe cachear el contenido generado y su interruptor
     * global —`laravel-pdf.cache.automatic`— viene apagado de fabrica. Confiar en
     * eso seria dejar que una linea en un `.env` metiera **el PDF de una tarjeta
     * en Redis**, indexado por la huella del HTML, durante veinticuatro horas.
     * Ahi dentro va un QR con el que se puede fichar en nombre de otra persona.
     * Se apaga aqui para que ninguna configuracion pueda encenderlo.
     */
    private function builderFor(CardFormat $format): PdfBuilder
    {
        $builder = (new PdfBuilder)->dontCache();

        return match ($format) {
            CardFormat::CARD => $builder
                ->paperSize(CardFormat::CARD_WIDTH_MM, CardFormat::CARD_HEIGHT_MM, 'mm')
                ->margins(0, 0, 0, 0),
            // El A4 lleva 8 mm de margen: es lo que necesita cualquier impresora
            // de oficina para no recortar la ultima fila de tarjetas.
            CardFormat::SHEET => $builder
                ->format(Format::A4)
                ->margins(8, 8, 8, 8),
        };
    }

    /**
     * @param  list<PrintableCard>  $cards
     */
    private function htmlFor(array $cards, CardFormat $format): string
    {
        $view = match ($format) {
            CardFormat::CARD => 'pdf.credential-card',
            CardFormat::SHEET => 'pdf.credential-sheet',
        };

        return View::make($view, [
            'cards' => array_map(
                fn (PrintableCard $card): array => [
                    'name' => $card->holder->fullName,
                    'department' => $card->holder->departmentName,
                    'site' => $card->holder->siteName,
                    'employeeCode' => $card->holder->employeeCode,
                    // El QR ya dibujado. **El payload en claro no llega a la
                    // plantilla**: lo que viaja es el SVG, que es la unica forma
                    // en la que ese token puede salir de este proceso.
                    'qr' => $this->qr->dataUriFor($card->payload),
                ],
                $cards,
            ),
            'widthMm' => CardFormat::CARD_WIDTH_MM,
            'heightMm' => CardFormat::CARD_HEIGHT_MM,
            'cardsPerSheet' => CardFormat::CARDS_PER_SHEET,
            'qrSizeMm' => Config::float('identity.credentials.card.qr_size_mm', 26.0),
            'brand' => [
                'name' => Config::get('branding.name'),
                'logo' => $this->logoDataUri(),
                'accent' => Config::string('branding.accent_color', '#111827'),
            ],
        ])->render();
    }

    /**
     * El logotipo del cliente incrustado en base64, o `null` si no hay ninguno
     * configurado o el fichero no esta.
     *
     * **Falla en silencio a proposito.** Un logotipo que falta no puede impedir
     * que se impriman las tarjetas de una temporada: el resultado seria que nadie
     * puede fichar el primer dia por culpa de una ruta mal escrita en un `.env`.
     */
    private function logoDataUri(): ?string
    {
        $path = Config::get('branding.logo_path');

        if (! \is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
