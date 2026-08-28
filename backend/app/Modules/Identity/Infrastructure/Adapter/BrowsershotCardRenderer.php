<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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
 * ## La tarjeta son dos mitades
 *
 * ```
 * |<------------- 85,6 mm ------------->|
 * |  IDENTIDAD (42,8)  |    QR (42,8)   |
 * |  nombre            |    [ 34,4 mm ] |
 * |  CODIGO (negrita)  |                |
 * |  departamento      |                |
 * |  centro            |                |
 * ```
 *
 * La mitad derecha es del simbolo y de nadie mas; en la izquierda manda el
 * nombre, con el codigo de empleado inmediatamente debajo y en negrita — es lo
 * que RRHH lee para emparejar una tarjeta suelta con su dueno sin escanearla, y
 * lo que hace falta para entrar al portal (ADR-015). El reparto lo calculan
 * {@see qrSideMm()} y {@see qrZoneMm()} y llega hecho a la plantilla.
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
    /**
     * Modulos del lado del simbolo: 33, la version 4 de QR.
     *
     * Un payload `FH1.<key_id>.<token>.<sig>` son 47 caracteres alfanumericos y
     * con nivel de correccion `Q` (RF-QR-05) eso cabe en la version 4, de 33 x 33
     * modulos. Es el peor caso para la geometria: un simbolo con MENOS modulos
     * tiene el modulo mas grande a igualdad de lado, asi que la zona tranquila
     * calculada con 33 le sobra. Si algun dia el payload creciera de version, el
     * modulo se encogeria y habria que rehacer esta cuenta — por eso esta escrita
     * aqui y no repartida por el CSS.
     */
    private const float SYMBOL_MODULES = 33.0;

    /**
     * Zona tranquila del estandar: 4 modulos por lado (ISO/IEC 18004).
     *
     * Sin ella el lector no encuentra el simbolo. Va en MODULOS y no en
     * milimetros porque es lo que dice el estandar, y traducirla a milimetros
     * una sola vez —aqui— evita que el CSS acabe con un numero magico que nadie
     * sabe de donde sale.
     */
    private const float QUIET_ZONE_MODULES = 4.0;

    /**
     * Alto del filete de color, en milimetros. **Es el mismo numero que usa el
     * CSS**, y por eso viaja a la plantilla en vez de estar escrito alli: la
     * banda blanca en la que se centra el QR es la tarjeta menos este filete, asi
     * que si los dos numeros divergieran el simbolo dejaria de estar centrado y
     * perderia zona tranquila por arriba.
     */
    private const float ACCENT_HEIGHT_MM = 2.5;

    public function __construct(private EndroidQrEncoder $qr) {}

    /**
     * Lado del simbolo impreso, en milimetros.
     *
     * **La mitad de la tarjeta es del QR, y se parte por el ancho.** La tarjeta
     * es apaisada (85,6 x 54): media a lo ancho son 42,8 mm y media a lo alto 27,
     * asi que partirla por el alto daria un simbolo con poco mas de la mitad de
     * area y ninguna ventaja a cambio.
     *
     * De esa mitad, el simbolo se queda con lo que cabe **dejando los 4 modulos
     * de zona tranquila a cada lado**: el hueco blanco no es decoracion, es parte
     * del simbolo. Sale de dividir la mitad entre 1 + 2 x 4/33 = 41/33, y se
     * redondea a la baja a la decima de milimetro para que ningun error de coma
     * flotante se coma un pelo de margen. Con la tarjeta ID-1 son 34,4 mm.
     *
     * **`qr_size_mm` es un suelo, no la medida.** RF-QR-05 pide un «tamano minimo
     * garantizado» y eso es lo que declara la configuracion; lo que se imprime es
     * todo lo que cabe, que hoy es bastante mas. Si una instalacion sube el
     * minimo por encima de la mitad, el simbolo crece y le quita sitio al texto
     * —el texto cede antes que el QR—, con el tope de lo que cabe a lo alto de la
     * tarjeta: por encima de ahi no se puede honrar el minimo sin recortar el
     * simbolo, y un QR recortado no lo lee nadie. Cuando el tope se aplica, el
     * minimo configurado deja de cumplirse y {@see warnIfMinimumDoesNotFit()} lo
     * dice en el log: acotar en silencio es lo que hace que nadie se entere.
     */
    public static function qrSideMm(): float
    {
        $footprint = 1 + 2 * self::QUIET_ZONE_MODULES / self::SYMBOL_MODULES;

        $halfCard = self::floorToTenth(CardFormat::CARD_WIDTH_MM / 2 / $footprint);
        $tallest = self::floorToTenth((CardFormat::CARD_HEIGHT_MM - self::ACCENT_HEIGHT_MM) / $footprint);
        $minimum = Config::float('identity.credentials.card.qr_size_mm', 26.0);

        return min(max($minimum, $halfCard), $tallest);
    }

    /**
     * Ancho de la franja de la tarjeta reservada al QR, zona tranquila incluida.
     *
     * La mitad de la tarjeta, salvo que un minimo configurado obligue al simbolo
     * a ser mayor: entonces la franja crece con el, porque la diferencia entre
     * las dos medidas ES la zona tranquila y esa no se negocia.
     *
     * Se redondea **hacia arriba** a la decima de milimetro, al reves que el lado
     * del simbolo: los dos redondeos empujan en la direccion de dejar mas blanco,
     * y asi la zona tranquila nunca se queda a un pelo de los 4 modulos por un
     * error de coma flotante.
     */
    public static function qrZoneMm(): float
    {
        $footprint = 1 + 2 * self::QUIET_ZONE_MODULES / self::SYMBOL_MODULES;

        return max(CardFormat::CARD_WIDTH_MM / 2, self::ceilToTenth(self::qrSideMm() * $footprint));
    }

    private static function floorToTenth(float $millimetres): float
    {
        return floor($millimetres * 10) / 10;
    }

    private static function ceilToTenth(float $millimetres): float
    {
        return ceil($millimetres * 10) / 10;
    }

    /**
     * Deja dicho en el log que el minimo configurado no cabe en la tarjeta.
     *
     * {@see qrSideMm()} acota `qr_size_mm` a lo que cabe a lo alto porque la
     * alternativa —imprimir el simbolo recortado— no la lee ningun lector. Lo
     * que no puede hacer es acotarlo **en silencio**: quien escribe
     * `QR_SIZE_MM=45` en el `.env` de una instalacion se queda creyendo que sus
     * tarjetas llevan un simbolo de 45 mm, y de la impresora salen de 41,4 sin
     * que nada lo diga. Un aviso aqui convierte un desajuste invisible —que solo
     * aparece midiendo una tarjeta impresa con una regla— en una linea de log.
     *
     * **Una vez por documento, no una por tarjeta.** Se llama desde
     * {@see htmlFor()}, que se ejecuta una sola vez por PDF: una hoja A4 de diez
     * tarjetas deja una linea, no diez. Por eso el aviso no vive dentro de
     * `qrSideMm()`, que se evalua varias veces por render.
     *
     * **Nivel `warning` y no `error`**: la impresion es correcta y las tarjetas
     * se leen; lo que hay es una configuracion que no se puede honrar.
     *
     * Sin PII: las dos medidas y nada mas (regla dura 21).
     */
    private function warnIfMinimumDoesNotFit(): void
    {
        $configured = Config::float('identity.credentials.card.qr_size_mm', 26.0);
        $effective = self::qrSideMm();

        if ($configured <= $effective) {
            return;
        }

        Log::warning(
            'Configured QR minimum size does not fit on the card; printing the largest symbol that fits.',
            ['configured_mm' => $configured, 'effective_mm' => $effective],
        );
    }

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
     * El documento que se le entrega a Chromium.
     *
     * **Publico a proposito**: la prueba de disposicion de la tarjeta afirma
     * sobre este HTML y no sobre una copia de las variables. Es lo que hace que
     * «el QR ocupa media tarjeta» y «el codigo va en negrita debajo del nombre»
     * sean afirmaciones sobre lo que se imprime de verdad. No forma parte del
     * puerto {@see CardRenderer}: quien llama desde la aplicacion solo conoce
     * `render()` y recibe bytes de PDF.
     *
     * @param  list<PrintableCard>  $cards
     */
    public function htmlFor(array $cards, CardFormat $format): string
    {
        $this->warnIfMinimumDoesNotFit();

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
            // La mitad de la tarjeta y el simbolo que cabe en ella. Las dos
            // medidas van juntas a la plantilla porque la diferencia entre ellas
            // ES la zona tranquila: separarlas seria dejar que el CSS decidiera
            // cuanto blanco rodea al QR.
            'qrZoneMm' => self::qrZoneMm(),
            'qrSizeMm' => self::qrSideMm(),
            'accentMm' => self::ACCENT_HEIGHT_MM,
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
