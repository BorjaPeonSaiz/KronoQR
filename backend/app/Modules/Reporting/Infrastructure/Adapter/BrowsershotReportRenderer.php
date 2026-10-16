<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Exception\ReportRenderingUnavailable;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;
use Throwable;

/**
 * {@see ReportDocumentRenderer} con `spatie/laravel-pdf` sobre Browsershot
 * (doc 02 §3.1, **RF-IN-04**).
 *
 * Mismo motor que el PDF de las tarjetas de `Identity` —nombrado en prosa porque
 * `Reporting` no puede importarlo (doc 02 §1.6)— y las mismas tres decisiones,
 * que se repiten aqui porque son de este documento y no de aquel:
 *
 * ## A4 **apaisado**
 *
 * El informe tiene once columnas. En vertical, una tabla de once columnas sale
 * con la fuente encogida hasta que no se lee o partida en dos bloques que nadie
 * sabe volver a juntar. Apaisado cabe entera, que es la unica disposicion en la
 * que un informe de horas sirve para lo que se imprime: mirarlo.
 *
 * ## `dontCache()` explicito, y es lo primero que se hace
 *
 * `laravel-pdf` sabe cachear el contenido generado, indexado por la huella del
 * HTML, y su interruptor global viene apagado **de fabrica**. Confiar en eso
 * seria dejar que una linea de un `.env` metiera en Redis, durante horas, un
 * documento con las horas nominales de la plantilla entera. Se apaga aqui para
 * que ninguna configuracion pueda encenderlo.
 *
 * ## No se guarda en ningun sitio
 *
 * Se devuelven los bytes: sin `save()`, sin `disk()`, sin `storage/`. El unico
 * paso por disco es el temporal que Browsershot usa para hablar con Chromium y
 * borra al terminar.
 *
 * ## Cualquier fallo sale como {@see ReportRenderingUnavailable}
 *
 * Es la razon de ser del puerto. Chromium es un proceso externo: puede no estar
 * instalado, puede no arrancar por memoria y puede agotar su tiempo. Los tres
 * llegarian al borde como excepciones distintas de una libreria de terceros y
 * acabarian en un `500` opaco. Traducidos aqui, el borde responde `503` con la
 * salida escrita: descargar el mismo informe en CSV o en XLSX.
 *
 * **El motivo tecnico se registra** —una sola linea, sin ningun dato del
 * informe (regla dura 21)— porque quien administra el servidor necesita saber
 * que le falta un paquete, y el `problem+json` no lleva la traza.
 *
 * ## Sin PDF etiquetado de serie (`REPORTING_PDF_TAGGED`)
 *
 * Puppeteer genera PDF etiquetado por omision, y en estos documentos el arbol
 * de estructura es casi todo el peso: un año del registro propio (1464 tramos,
 * 36 paginas) llevaba 27 916 elementos `StructElem` —un `TD` y un `NonStruct`
 * por celda— sin comprimir, 4,2 de sus 4,5 MiB. Sin etiquetar pesa 0,34 MiB y
 * se compone en la mitad de tiempo (medido en el bloque 23 de la 2.2.1). El
 * texto sigue siendo seleccionable y buscable; lo que se pierde es la
 * estructura para lectores de pantalla, que el mismo dato ya tiene en la
 * pantalla del portal y del panel y en el CSV. Una instalacion que la necesite
 * en el PDF la enciende por configuracion.
 */
final readonly class BrowsershotReportRenderer implements ReportDocumentRenderer
{
    public function __construct(private bool $tagged = false) {}

    public function renderPdf(string $html, string $footerHtml): string
    {
        $tagged = $this->tagged;

        try {
            return (new PdfBuilder)
                ->dontCache()
                ->format(Format::A4)
                ->landscape()
                // Margenes generosos abajo: el pie sellado de cada pagina —fecha,
                // emisor, periodo y huella— ocupa dos lineas, y sin sitio
                // Chromium lo pisa con la ultima fila de la tabla.
                ->margins(10, 10, 18, 10)
                ->html($html)
                // El pie va por aqui y no dentro del cuerpo: es la unica forma de
                // que Chromium lo repita en TODAS las paginas. Ver el puerto.
                ->footerHtml($footerHtml)
                // Explicito en los dos sentidos: Browsershot solo sabe pedirlo
                // a `true`, y callarse es heredar el `true` de Puppeteer.
                ->withBrowsershot(static function (Browsershot $browsershot) use ($tagged): void {
                    $browsershot->setOption('tagged', $tagged);
                })
                ->generatePdfContent();
        } catch (Throwable $failure) {
            Log::error('reporting.pdf_engine_unavailable', [
                'exception' => $failure::class,
                'reason' => $failure->getMessage(),
            ]);

            throw ReportRenderingUnavailable::engineFailed($failure);
        }
    }
}
