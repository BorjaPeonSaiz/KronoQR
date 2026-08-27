<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Compliance\Infrastructure\Export\CsvLegalExportWriter;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Response\PersonalRecordCsv;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/*
 * Los dos CSV del producto se escriben con el mismo dialecto (RL-06, RF-IN-05,
 * RF-ID-05, RL-05).
 *
 * **Por que existe esta prueba.** KronoQR entrega dos ficheros CSV: el de la
 * Inspeccion y el historico propio del portal. Su contenido es distinto a
 * proposito; su codificacion no puede serlo. Cuando cada escritor declaraba sus
 * constantes por separado, dejaron de coincidir sin que fallara nada: el del
 * portal salia con `\r\n` y el de la Inspeccion con `\n`, es decir, el producto
 * paso a tener dos formatos y nadie se entero porque ninguna prueba comparaba
 * un fichero con el otro. Cada uno tenia las suyas y las dos pasaban.
 *
 * Asi que esto no comprueba que un fichero sea correcto —de eso se ocupan
 * `tests/Integration/Compliance/LegalExportTest.php` y
 * `tests/Feature/Reporting/MyWorkDaysTest.php`—: comprueba que **los dos son el
 * mismo formato**, comparando sus bytes de envoltura entre si y con
 * {@see CsvDialect}. Una divergencia futura falla aqui en lugar de descubrirse
 * cuando un inspector abra un adjunto.
 *
 * **Integracion y no unitaria** porque los dos escritores resuelven sus rotulos
 * con `Lang`, y una prueba de la suite Unit no arranca el framework (doc 02
 * §9.5: la tabla no la decide quien implementa; esto es «esquema o formato
 * declarado», y se ejercita el escritor de verdad, no un doble).
 */

beforeEach(function (): void {
    // El idioma es configuracion de la instalacion (regla dura 13, ADR-017): se
    // fija para que la prueba no dependa del `.env` de quien la ejecute.
    App::setLocale('es');
});

/**
 * Los bytes del CSV que se entrega a la Inspeccion (RF-IN-05).
 */
function bytesDeLaExportacionLegal(): string
{
    $path = storage_path('framework/testing/csv-dialect-'.Str::random(10).'.csv');

    // Sin ninguna fila: lo que se compara es la envoltura —el manifiesto y los
    // rotulos—, y para eso no hace falta base de datos ni plantilla.
    (new CsvLegalExportWriter)->write(
        new LegalExportManifest(
            generatedAt: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
            period: LegalExportPeriod::between('2026-03-01', '2026-03-31'),
            scope: LegalExportScope::everyone(),
        ),
        $path,
        [],
    );

    $bytes = (string) file_get_contents($path);

    unlink($path);

    return $bytes;
}

/**
 * Los bytes del CSV que se descarga del portal (RF-ID-05, RL-05).
 */
function bytesDelHistoricoPropio(): string
{
    $response = PersonalRecordCsv::respond(new WorkDayJournal(
        employeeUuid: '0195f0a0-0000-7000-8000-000000000000',
        timeZone: 'Europe/Madrid',
        range: DateRange::between('2026-03-01', '2026-03-31'),
        days: [],
    ));

    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/**
 * El fin de linea que el fichero usa de verdad, leido de sus bytes y no de una
 * constante: si alguien cambia el escritor, esto cambia con el.
 */
function finDeLineaDe(string $bytes): string
{
    $lf = strpos($bytes, "\n");

    if ($lf === false) {
        return '';
    }

    return $lf > 0 && $bytes[$lf - 1] === "\r" ? "\r\n" : "\n";
}

it('escribe los dos CSV del producto con los mismos bytes de envoltura', function (): void {
    $legal = bytesDeLaExportacionLegal();
    $propio = bytesDelHistoricoPropio();

    // La marca de orden de bytes: sin ella, Excel con configuracion regional
    // española lee el fichero en Windows-1252 y los apellidos salen rotos.
    $bom = CsvDialect::BYTE_ORDER_MARK;

    expect(substr($legal, 0, \strlen($bom)))->toBe($bom, 'La exportacion legal no lleva BOM.')
        ->and(substr($propio, 0, \strlen($bom)))->toBe($bom, 'El historico propio no lleva BOM.');

    // El fin de linea, que es exactamente lo que divergio: `\r\n` en uno y `\n`
    // en el otro, con el docblock de uno de ellos afirmando que compartian
    // formato.
    expect(finDeLineaDe($legal))->toBe(CsvDialect::END_OF_LINE)
        ->and(finDeLineaDe($propio))->toBe(CsvDialect::END_OF_LINE)
        ->and(finDeLineaDe($legal))->toBe(finDeLineaDe($propio));

    // Y no solo el primero: ni un salto suelto en todo el fichero. Un CSV con
    // dos finales de linea distintos rompe a cualquier lector estricto.
    expect(preg_match('/(?<!\r)\n/', $legal))->toBe(0, 'La exportacion legal mezcla `\n` con `\r\n`.')
        ->and(preg_match('/(?<!\r)\n/', $propio))->toBe(0, 'El historico propio mezcla `\n` con `\r\n`.');
})->group('RL-06', 'RF-IN-05', 'RF-ID-05', 'RL-05');

it('separa las columnas de los dos CSV con el mismo delimitador', function (): void {
    // Donde la coma es el separador decimal, Excel espera `;`. Con `,` los dos
    // ficheros se abririan con todas las columnas en la primera celda.
    $legal = bytesDeLaExportacionLegal();
    $propio = bytesDelHistoricoPropio();

    expect($legal)->toContain(CsvDialect::DELIMITER)
        ->and($propio)->toContain(CsvDialect::DELIMITER)
        // Y con el delimitador correcto no hace falta el otro: si apareciera una
        // coma separando celdas, la fila de rotulos tendria mas campos leida con
        // `,` que con `;`.
        ->and(count(str_getcsv(lineaDeRotulos($legal), ',', '"', '')))
        ->toBeLessThan(count(str_getcsv(lineaDeRotulos($legal), CsvDialect::DELIMITER, '"', '')));
})->group('RL-06', 'RF-IN-05', 'RF-ID-05');

/**
 * La fila de rotulos: la primera que trae mas de una decena de columnas. El
 * bloque de criterios que la precede son filas de dos celdas.
 */
function lineaDeRotulos(string $bytes): string
{
    foreach (explode(CsvDialect::END_OF_LINE, $bytes) as $line) {
        if (substr_count($line, CsvDialect::DELIMITER) > 10) {
            return $line;
        }
    }

    return '';
}
