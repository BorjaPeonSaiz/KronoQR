<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\ReportSubject;
use App\Modules\Reporting\Http\Response\PeriodReportPdf;
use App\Modules\Reporting\Http\Support\PeriodReportDigest;
use App\Modules\Reporting\Infrastructure\Adapter\BrowsershotReportRenderer;
use Illuminate\Support\Facades\App;
use Tests\Support\Reporting\FakeReportDocumentRenderer;

/*
 * **El PDF sellado**: fecha, emisor, periodo y huella del contenido en el pie de
 * cada pagina (**RF-IN-04**, tarea 2.9).
 *
 * ## Aqui si se arranca Chromium
 *
 * Al contrario que las pruebas de feature, que sustituyen el motor por un doble
 * porque lo que comprueban es el borde. Aqui se compone el documento de verdad,
 * que es lo unico que demuestra que el motor de esta instalacion funciona.
 *
 * **Lo que no se hace es leer el texto de dentro del PDF.** Chromium incrusta la
 * tipografia en subconjunto y codifica el contenido contra un CMap propio del
 * fichero: buscar «2026-04-01» en los bytes no encuentra nada aunque la fecha
 * este impresa. Extraerlo exigiria un extractor de PDF —no hay `pdftotext` en el
 * contenedor— y añadir una dependencia de Composer para leer un fichero en una
 * prueba es superficie de ataque y de mantenimiento por un caso. El reparto que
 * queda es honesto: el PDF real demuestra que **el sello cambia el documento**, y
 * el HTML que se le entrega al motor —que es texto— demuestra **que dice**.
 *
 * ## Por que el informe se construye a mano
 *
 * No hace falta base de datos: lo que se prueba es la composicion del documento
 * a partir de un `PeriodReport`, y construirlo con cifras conocidas permite
 * **cambiar una hora y solo una** para comprobar que la huella se mueve. Con
 * jornadas sembradas, cambiar una hora significa recalcular la proyeccion y
 * dejaria la duda de si lo que cambio fue otra cosa.
 */

beforeEach(function (): void {
    App::setLocale('es');
});

/**
 * Un informe de una fila con cifras conocidas.
 *
 * `$workedMinutes` es el unico parametro: es lo que se cambia para comprobar
 * que la huella lo nota.
 */
function informeSellable(int $workedMinutes = 9720): PeriodReport
{
    $subject = ReportSubject::employee(
        '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        '739104',
        'Lucía Amrani',
        3,
        'Cocina',
    );

    return new PeriodReport(
        rows: [
            new PeriodReportRow(
                subject: $subject,
                periodStart: new DateTimeImmutable('2026-03-01', new DateTimeZone('UTC')),
                periodEnd: new DateTimeImmutable('2026-03-31', new DateTimeZone('UTC')),
                workedMinutes: $workedMinutes,
                shiftCount: 21,
                daysInPeriod: 31,
                daysWithActivity: 21,
                openShiftDays: 0,
                incidentDays: 1,
                contractedMinutes: 9257,
                daysWithoutContract: 0,
            ),
        ],
        range: DateRange::between('2026-03-01', '2026-03-31'),
        granularity: ReportGranularity::Month,
        grouping: ReportGrouping::Employee,
        timeZone: 'Europe/Madrid',
        // 07:12 en Madrid: si el sello saliera en UTC diria 05:12 y pareceria
        // generado por otro sistema (regla dura 3, ADR-040).
        generatedAt: new DateTimeImmutable('2026-04-01T05:12:03Z', new DateTimeZone('UTC')),
        criteria: ['criteria.source', 'criteria.work_date'],
        contractCoverage: new ContractCoverage(0, 0),
    );
}

/**
 * Chromium en el contenedor `app`. Si algun dia no estuviera, esta prueba tiene
 * que decir **por que** se salta en vez de fallar con un error de proceso.
 */
function hayChromium(): bool
{
    return is_executable('/usr/bin/chromium') || is_executable('/usr/bin/chromium-browser');
}

/**
 * El PDF compuesto por el motor de verdad.
 */
function pdfSellado(PeriodReport $informe, string $emisor, string $huella): string
{
    ob_start();
    app(PeriodReportPdf::class)->respond($informe, $emisor, $huella)->toResponse()->sendContent();

    return (string) ob_get_clean();
}

it('compone un PDF de verdad, y el sello forma parte del documento', function (): void {
    // POR QUE NO SE BUSCA EL TEXTO DENTRO DEL PDF. Chromium incrusta la
    // tipografia en subconjunto: el contenido va comprimido y codificado contra
    // un CMap propio del fichero, asi que buscar «2026-04-01» en los bytes no
    // encuentra nada aunque la fecha este impresa. Extraerlo exigiria un
    // extractor de PDF, y añadir una dependencia de Composer para leer un
    // fichero en una prueba es superficie de ataque y de mantenimiento por un
    // caso (no hay `pdftotext` en el contenedor).
    //
    // Lo que si se puede afirmar, y es lo que importa: que el documento se
    // compone de verdad, y que **cambiar solo la huella cambia el PDF**. Si el
    // sello no llegara al papel, los dos ficheros serian identicos. Que el pie
    // lleve las cuatro cosas lo comprueba el caso siguiente sobre el HTML que se
    // le entrega al motor, que es texto y si se puede leer.
    $informe = informeSellable();
    $huella = PeriodReportDigest::of($informe)->toText();

    $conSuHuella = pdfSellado($informe, 'Marta Ibáñez', $huella);
    $conOtraHuella = pdfSellado($informe, 'Marta Ibáñez', str_repeat('a', 64));

    expect($conSuHuella)->toStartWith('%PDF-')
        ->and(strlen($conSuHuella))->toBeGreaterThan(2000)
        ->and($conOtraHuella)->not->toBe($conSuHuella);
})->group('RF-IN-04')->skip(! hayChromium(), 'Chromium no esta instalado en este contenedor.');

it('repite en cada pagina un pie con fecha local, emisor, periodo y huella', function (): void {
    // El pie va por el `footerHtml` del motor y no dentro del cuerpo: es la
    // unica forma de que Chromium lo imprima en TODAS las paginas. Un pie
    // escrito en el flujo del documento saldria una sola vez, al final, y una
    // hoja suelta fotocopiada del monton no diria de que informe es.
    FakeReportDocumentRenderer::bind();

    $informe = informeSellable();
    $huella = PeriodReportDigest::of($informe)->toText();

    app(PeriodReportPdf::class)->respond($informe, 'Marta Ibáñez', $huella);

    $pie = FakeReportDocumentRenderer::lastFooter();

    // Las cuatro cosas del sello. Se afirman una a una y no encadenadas tras un
    // `->not`: Pest estrecha el tipo de la expectativa al negar, y lo que se
    // gana con la cadena se paga con una afirmacion que deja de comprobarse.
    expect($pie)->toContain('2026-04-01 07:12');
    // 07:12 de Madrid, no 05:12 de UTC (regla dura 3, ADR-040).
    expect($pie)->toContain('Europe/Madrid');
    // El nombre de la cuenta, nunca su correo (regla dura 12).
    expect($pie)->toContain('Marta Ibáñez');
    expect($pie)->not->toContain('@');
    expect($pie)->toContain('2026-03-01');
    expect($pie)->toContain('2026-03-31');
    expect($pie)->toContain($huella);
    // Y ningun nombre de empleado: el unico nombre del pie es el de quien
    // responde del informe (regla dura 21).
    expect($pie)->not->toContain('Amrani');

    // El cuerpo si lleva las horas, en `HH:MM` y sin ninguna decimal.
    expect(FakeReportDocumentRenderer::lastHtml())->toContain('162:00');
    expect(FakeReportDocumentRenderer::lastHtml())->not->toContain('162,0');
})->group('RF-IN-04');

it('cambia la huella cuando cambia una sola hora del informe', function (): void {
    // Es la propiedad que justifica que la huella exista. Si no cambiara, el
    // sello diria que dos informes con horas distintas son el mismo documento, y
    // eso es peor que no sellar nada.
    $original = PeriodReportDigest::of(informeSellable());
    // Un minuto de diferencia en una sola fila.
    $corregido = PeriodReportDigest::of(informeSellable(9721));

    expect($original->toText())->toMatch('/^[0-9a-f]{64}$/')
        ->and($corregido->toText())->not->toBe($original->toText());
})->group('RF-IN-04');

it('da la misma huella dos veces para el mismo contenido, aunque el sello cambie', function (): void {
    // La otra mitad: la huella es del CONTENIDO y no del binario. El instante de
    // generacion y el emisor no entran, asi que dos PDF del mismo informe
    // impresos con dos minutos de diferencia —y por tanto distintos byte a
    // byte— llevan la misma huella. Sin esto, comparar dos copias seria
    // imposible.
    expect(PeriodReportDigest::of(informeSellable())->toText())
        ->toBe(PeriodReportDigest::of(informeSellable())->toText());
})->group('RF-IN-04');

it('deja fuera de la huella el idioma del documento', function (): void {
    // Los criterios viajan por sus CLAVES, sin traducir. Un informe descargado en
    // ingles y otro en español dicen lo mismo y tienen que poder demostrarlo.
    $enEspanol = PeriodReportDigest::of(informeSellable())->toText();

    App::setLocale('en');

    expect(PeriodReportDigest::of(informeSellable())->toText())->toBe($enEspanol);
})->group('RF-IN-04');

it('usa el motor real y no el doble de las pruebas de feature', function (): void {
    // Control: sin esto, las dos primeras pruebas pasarian igual con un doble
    // enlazado por otra prueba de la suite, y no estarian comprobando ningun PDF.
    expect(app(ReportDocumentRenderer::class))->toBeInstanceOf(BrowsershotReportRenderer::class);
})->group('RF-IN-04');
