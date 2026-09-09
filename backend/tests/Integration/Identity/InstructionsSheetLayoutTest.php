<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Identity\Infrastructure\Adapter\BrowsershotInstructionsSheetRenderer;
use App\Modules\Shared\Domain\ValueObject\Branding;
use Tests\Support\Product\FixedLogo;

/*
 * **LA HOJA CABE EN UNA CARA** (tarea 5.11b, **RL-05**, ficha 5.11b decision 1).
 *
 * ## Aqui si se arranca Chromium
 *
 * Como en `Tests\Integration\Reporting\PeriodReportPdfSealTest`, y por el mismo
 * motivo: hay una afirmacion que **solo** se puede hacer sobre el PDF de verdad.
 * Aquella es que el sello cambia el documento; esta es que el documento tiene
 * **exactamente una pagina**. Ninguna prueba sobre el HTML puede decirlo: quien
 * decide donde salta la pagina es el motor, con la tipografia que tenga la
 * maquina y el texto del idioma que toque.
 *
 * Y es la afirmacion que sostiene la decision de producto: la hoja se entrega en
 * mano junto con la tarjeta y el PIN, en un unico acto. Una segunda cara es una
 * cara que la mitad de la gente no lee.
 *
 * ## Los dos idiomas, porque el texto ingles no mide lo mismo
 *
 * Una hoja que cabe en castellano puede no caber en ingles —o al reves— y el
 * fallo aparece el dia que un cliente cambia `LOCALE_DEFAULT`. Se comprueban
 * los dos idiomas que trae el producto.
 *
 * ## Lo que NO se busca dentro del PDF
 *
 * El texto. Chromium incrusta la tipografia en subconjunto y codifica el
 * contenido contra un CMap propio del fichero: buscar la direccion del portal en
 * los bytes no la encuentra aunque este impresa, y no hay extractor de PDF en el
 * contenedor. El reparto es el mismo que en el informe sellado y es honesto: el
 * PDF real demuestra **cuantas paginas hay**, y el HTML que se le entrega al
 * motor —que es texto— demuestra **que dice**.
 */

/** La marca de un cliente cualquiera, para que nada este escrito en la hoja. */
function marcaDeLaHoja(): Branding
{
    return new Branding(
        applicationName: 'Hotel Marina',
        logoPath: null,
        accentColor: '#0f766e',
    );
}

function renderizadorDeLaHoja(): BrowsershotInstructionsSheetRenderer
{
    // Se construye y no se pide al contenedor, como en `CredentialCardLayoutTest`:
    // resolverlo arrastraria el proveedor de configuracion, su cache y el
    // `FeatureGate` de la licencia, y lo que se mide aqui son PAGINAS.
    return new BrowsershotInstructionsSheetRenderer(FixedLogo::none());
}

/**
 * Chromium en el contenedor `app`. Si algun dia no estuviera, esta prueba tiene
 * que decir **por que** se salta en vez de fallar con un error de proceso.
 */
function hayChromiumParaLaHoja(): bool
{
    return is_executable('/usr/bin/chromium') || is_executable('/usr/bin/chromium-browser');
}

/**
 * Cuantas paginas tiene el PDF.
 *
 * Se cuentan los objetos `/Type /Page` **que no son el arbol** (`/Type /Pages`),
 * que es como se cuenta una pagina en un PDF sin abrirlo con una libreria. El
 * `[^s]` de la expresion es toda la diferencia entre las dos cosas.
 */
function paginasDelPdf(string $pdf): int
{
    $paginas = preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);

    // `false` es «la expresion no compila», no «cero paginas». Se distingue
    // porque un `0` silencioso convertiria esta prueba en una que no comprueba
    // nada.
    expect($paginas)->not->toBeFalse();

    return $paginas === false ? -1 : $paginas;
}

it('compone la hoja en UNA sola cara, en los dos idiomas del producto', function (string $locale): void {
    $pdf = renderizadorDeLaHoja()->render($locale, marcaDeLaHoja(), 'https://hotel-marina.example/portal/');

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(2000)
        ->and(paginasDelPdf($pdf))->toBe(1);
})->with(['es', 'en'])
    ->group('RL-05', 'RF-QR-06')
    ->skip(! hayChromiumParaLaHoja(), 'Chromium no esta instalado en este contenedor.');

it('imprime la direccion del portal y la marca de la instalacion', function (string $locale): void {
    // Sobre el HTML que recibe el motor, que es donde se puede leer (ver la
    // cabecera). La direccion del portal es lo que ningun PDF estatico del
    // paquete de documentacion podria saber, y la marca es RF-PD-08.
    $html = renderizadorDeLaHoja()->htmlFor($locale, marcaDeLaHoja(), 'https://hotel-marina.example/portal/');

    expect($html)->toContain('https://hotel-marina.example/portal/')
        ->and($html)->toContain('Hotel Marina')
        ->and($html)->toContain('#0f766e')
        // Y ninguna referencia a la red: pictogramas y logotipo van dentro del
        // documento (ADR-016). La unica direccion que aparece es la del portal
        // del propio cliente, y es texto impreso, no un recurso que buscar.
        ->and($html)->not->toContain('src="http')
        ->and($html)->not->toContain('href="http')
        ->and($html)->not->toContain('@import');
})->with(['es', 'en'])->group('RL-05', 'RF-PD-08');

it('escribe cada frase en el idioma que se le pide, sin tocar el del proceso', function (): void {
    // El panel ofrece un boton por cada idioma activo: el mismo servidor tiene
    // que poder imprimir las dos hojas seguidas sin que la primera cambie el
    // idioma de la segunda ni el de la peticion que las genera.
    $renderer = renderizadorDeLaHoja();

    $castellano = $renderer->htmlFor('es', marcaDeLaHoja(), 'https://hotel-marina.example/portal/');
    $ingles = $renderer->htmlFor('en', marcaDeLaHoja(), 'https://hotel-marina.example/portal/');

    expect($castellano)->toContain(__('instructions-sheet.title', [], 'es'))
        ->and($ingles)->toContain(__('instructions-sheet.title', [], 'en'))
        ->and($castellano)->not->toBe($ingles)
        // El pie lleva la marca sustituida, no el marcador.
        ->and($castellano)->not->toContain(':app_name')
        ->and($ingles)->not->toContain(':app_name')
        ->and($castellano)->not->toContain(':portal_url');
})->group('RL-05');

it('usa el motor real y no el doble de las pruebas de feature', function (): void {
    // Control: sin esto, las pruebas de arriba pasarian igual con un doble
    // enlazado por otra prueba de la suite.
    expect(app(InstructionsSheetRenderer::class))->toBeInstanceOf(BrowsershotInstructionsSheetRenderer::class);
})->group('RL-05');
