<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\CorrectionAuthor;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ShiftMarks;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Http\Response\PersonalRecordCsv;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordFormat;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordLayout;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordPdfWriter;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\Branding;
use Illuminate\Support\Facades\App;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Product\FixedLogo;
use Tests\Support\Reporting\FakeReportDocumentRenderer;

/*
 * El CSV y el PDF del registro propio dicen lo mismo (R1-RV-02) y cada hora
 * declara su desfase respecto a UTC (R1-RV-01). RF-ID-05, RL-05, RN-05.
 *
 * **Por que existe.** Hasta la 2.3.0 cada formato tenia su lista de columnas y
 * su forma de escribir cada celda, y ya divergian: un estado sin traduccion
 * salia como clave en el CSV y como valor en el PDF, y el PDF no decia en que
 * desfase estaba cada hora. Ahora salen de `PersonalRecordLayout`, y esta prueba
 * compara **lo que entregan los dos escritores de verdad** —los bytes del CSV y
 * la tabla del HTML que recibe el motor de PDF— para los mismos datos, con los
 * dos cambios de hora del año dentro.
 *
 * **Integracion y no unitaria** porque los dos escritores resuelven sus rotulos
 * con `Lang` y el PDF compone una vista Blade (doc 02 §9.5). El motor de PDF se
 * sustituye por {@see FakeReportDocumentRenderer}, que guarda el HTML.
 */

beforeEach(function (): void {
    App::setLocale('es');
    FakeReportDocumentRenderer::bind();
    App::instance(BrandingProvider::class, new FixedBranding(new Branding(
        applicationName: 'Hotel Marina',
        logoPath: null,
        accentColor: '#1F4E79',
    )));
    App::instance(BrandingLogoReader::class, FixedLogo::none());
});

const PARIDAD_REGISTRO_MADRID = 'Europe/Madrid';

function paridadInstante(string $utc): DateTimeImmutable
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
}

function paridadTramo(string $uuid, string $entrada, ?string $salida, string $estado, ?int $minutos): JournalShiftEntry
{
    return new JournalShiftEntry(
        uuid: $uuid,
        version: 1,
        status: $estado,
        siteId: 1,
        timeZone: PARIDAD_REGISTRO_MADRID,
        clockedInAt: paridadInstante($entrada),
        clockInRecordedAt: paridadInstante($entrada),
        clockInSource: 'qr_kiosk',
        clockedOutAt: $salida === null ? null : paridadInstante($salida),
        clockOutRecordedAt: $salida === null ? null : paridadInstante($salida),
        clockOutSource: $salida === null ? null : 'pin_kiosk',
        durationMinutes: $minutos,
        recordedAt: paridadInstante($salida ?? $entrada),
        closedBy: $salida === null ? null : JournalShiftEntry::CLOSED_BY_CLOCK_OUT,
    );
}

function paridadCorreccion(string $uuid, string $momento, ?string $explicacion): JournalCorrection
{
    $instante = paridadInstante($momento);

    return new JournalCorrection(
        shiftEntryUuid: $uuid,
        action: 'voided',
        performedAt: $instante,
        performedBy: new CorrectionAuthor('0199f0c2-0000-7000-8000-0000000000aa', 'Marta Ibáñez'),
        reasonCode: 'ERROR_DE_ESCANEO_DUPLICADO',
        reasonText: $explicacion,
        before: new ShiftMarks(1, $instante, $instante, 0),
        after: null,
    );
}

/**
 * Un registro con los dos cambios de hora de 2026 en Madrid:
 *
 * - el 29 de marzo a las 01:00 UTC el reloj salta de 02:00 a 03:00 (`+01:00` →
 *   `+02:00`): una correccion firmada antes y un tramo que entra antes y sale
 *   despues;
 * - el 25 de octubre a las 01:00 UTC vuelve de 03:00 a 02:00 (`+02:00` →
 *   `+01:00`): un turno de noche del 24 que entra a las 22:00 y sale a las
 *   06:00 dura nueve horas, no ocho, y solo el desfase lo explica;
 *
 * mas una jornada que se quedo sin tramos, con su anulacion, y un turno todavia
 * abierto.
 */
function paridadRegistro(): WorkDayJournal
{
    $madrid = PARIDAD_REGISTRO_MADRID;

    return new WorkDayJournal(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        timeZone: $madrid,
        range: DateRange::between('2026-03-01', '2026-10-31'),
        days: [
            new JournalWorkDay('2026-03-29', $madrid, null, [
                // 01:30 +01:00 → 07:00 +02:00: cuatro horas y media reales.
                paridadTramo('t-marzo', '2026-03-29T00:30:00Z', '2026-03-29T05:00:00Z', 'closed', 270),
            ], [
                paridadCorreccion('t-marzo', '2026-03-29T00:45:00Z', 'Doble lectura; con «comillas» y ; punto y coma'),
            ]),
            // Todos sus tramos anulados: la jornada no desaparece (regla dura 5).
            new JournalWorkDay('2026-04-02', $madrid, null, [], [
                paridadCorreccion('t-abril', '2026-04-03T08:00:00Z', null),
            ]),
            new JournalWorkDay('2026-10-24', $madrid, null, [
                // 22:00 +02:00 → 06:00 +01:00: nueve horas, un solo tramo (RN-05).
                paridadTramo('t-octubre', '2026-10-24T20:00:00Z', '2026-10-25T05:00:00Z', 'closed', 540),
            ], []),
            new JournalWorkDay('2026-10-30', $madrid, null, [
                paridadTramo('t-abierto', '2026-10-30T07:00:00Z', null, 'open', null),
            ], []),
        ],
    );
}

/**
 * Las filas de datos del CSV, ya decodificadas: lo que hay despues de la fila de
 * rotulos, que es la primera tras la linea en blanco.
 *
 * @return array{header: list<string>, rows: list<list<string>>}
 */
function paridadTablaCsv(WorkDayJournal $journal): array
{
    $response = PersonalRecordCsv::respond($journal);

    ob_start();
    $response->sendContent();
    $bytes = (string) ob_get_clean();

    $lines = explode("\r\n", rtrim(substr($bytes, 3), "\r\n"));
    $blank = array_search('', $lines, true);

    expect($blank)->toBeInt();

    $table = array_map(
        static fn (string $line): array => array_map(strval(...), str_getcsv($line, ';', '"', '')),
        array_slice($lines, (int) $blank + 1),
    );

    return ['header' => array_shift($table) ?? [], 'rows' => $table];
}

/**
 * La tabla del HTML que recibio el motor de PDF.
 *
 * @return array{header: list<string>, rows: list<list<string>>}
 */
function paridadTablaPdf(WorkDayJournal $journal): array
{
    app(PersonalRecordPdfWriter::class)->render(
        $journal,
        'Lucía Amrani Ruiz',
        str_repeat('a', 64),
        paridadInstante('2026-11-02T09:00:00Z'),
    );

    $document = new DOMDocument;
    // Las etiquetas de HTML5 que libxml no conoce dan avisos, no errores.
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.FakeReportDocumentRenderer::lastHtml());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    $cells = static function (string $query, ?DOMNode $context = null) use ($xpath): array {
        $nodes = $xpath->query($query, $context);
        $texts = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $texts[] = trim($node->textContent);
            }
        }

        return $texts;
    };

    $rows = [];
    $bodyRows = $xpath->query('//table[contains(@class, "data")]/tbody/tr');

    foreach ($bodyRows === false ? [] : $bodyRows as $row) {
        if ($row instanceof DOMElement) {
            $rows[] = $cells('./td', $row);
        }
    }

    return ['header' => $cells('//table[contains(@class, "data")]/thead/tr/th'), 'rows' => $rows];
}

/**
 * Una fila del CSV como diccionario por clave de columna.
 *
 * @param  list<string>  $row
 * @return array<string, string>
 */
function paridadPorColumna(array $row, PersonalRecordFormat $format): array
{
    return array_combine(PersonalRecordLayout::columns($format), $row);
}

it('saca del CSV y del PDF las mismas filas con los mismos valores, cambios de hora incluidos', function (): void {
    $journal = paridadRegistro();
    $csv = paridadTablaCsv($journal);
    $pdf = paridadTablaPdf($journal);

    expect($csv['rows'])->toHaveCount(6)
        ->and($pdf['rows'])->toHaveCount(\count($csv['rows']))
        ->and($csv['header'])->toHaveCount(\count(PersonalRecordLayout::COLUMNS))
        ->and($pdf['header'])->toHaveCount(\count(PersonalRecordLayout::columns(PersonalRecordFormat::Pdf)));

    $tipos = ['TRAMO' => 'Tramo', 'CORRECCION' => 'Corrección'];

    foreach ($csv['rows'] as $index => $csvRow) {
        $deCsv = paridadPorColumna($csvRow, PersonalRecordFormat::Csv);
        $dePdf = paridadPorColumna($pdf['rows'][$index], PersonalRecordFormat::Pdf);

        // Cada celda del PDF es la del CSV en la misma columna. La unica que se
        // escribe distinto es el tipo de fila, que en el PDF se traduce.
        foreach ($dePdf as $column => $value) {
            $expected = $column === 'record_type' ? $tipos[$deCsv[$column]] : $deCsv[$column];

            expect($value)->toBe($expected, 'fila '.$index.', columna '.$column);
        }
    }
})->group('RF-ID-05', 'RL-05', 'RN-05', 'RN-13');

it('declara en cada hora su desfase respecto a UTC, el de ese instante', function (): void {
    $filas = array_map(
        static fn (array $row): array => paridadPorColumna($row, PersonalRecordFormat::Csv),
        paridadTablaCsv(paridadRegistro())['rows'],
    );

    // Marzo: entra a las 01:30 en invierno y sale a las 07:00 en verano.
    expect($filas[0])->toMatchArray([
        'record_type' => 'TRAMO',
        'local_in' => '2026-03-29 01:30',
        'offset_in' => 'UTC+01:00',
        'local_out' => '2026-03-29 07:00',
        'offset_out' => 'UTC+02:00',
        'duration' => '04:30',
        'utc_in' => '2026-03-29T00:30:00Z',
        'utc_out' => '2026-03-29T05:00:00Z',
    ])
        // La correccion de las 01:45, todavia en invierno.
        ->and($filas[1])->toMatchArray([
            'record_type' => 'CORRECCION',
            'correction_local_at' => '2026-03-29 01:45',
            'correction_offset' => 'UTC+01:00',
            'offset_in' => '',
        ])
        // La jornada sin tramos no tiene horas, asi que tampoco desfase.
        ->and($filas[2])->toMatchArray([
            'work_date' => '2026-04-02',
            'status' => 'Sin tramos registrados',
            'offset_in' => '',
            'offset_out' => '',
        ])
        ->and($filas[3]['correction_offset'])->toBe('UTC+02:00')
        // Octubre: 22:00 → 06:00 son nueve horas porque el reloj se atraso una.
        ->and($filas[4])->toMatchArray([
            'work_date' => '2026-10-24',
            'local_in' => '2026-10-24 22:00',
            'offset_in' => 'UTC+02:00',
            'local_out' => '2026-10-25 06:00',
            'offset_out' => 'UTC+01:00',
            'duration' => '09:00',
        ])
        // Abierto: entrada con su desfase, salida y desfase vacios.
        ->and($filas[5])->toMatchArray([
            'local_in' => '2026-10-30 08:00',
            'offset_in' => 'UTC+01:00',
            'local_out' => '',
            'offset_out' => '',
            'status' => 'Turno abierto',
        ]);
})->group('RF-ID-05', 'RL-05', 'RN-05');

it('rotula en el PDF el desfase de cada hora y deja fuera solo lo que sirve a una maquina', function (): void {
    $pdf = paridadTablaPdf(paridadRegistro());

    expect(PersonalRecordLayout::columns(PersonalRecordFormat::Pdf))
        ->toBe(array_values(array_diff(PersonalRecordLayout::COLUMNS, ['time_zone', 'utc_in', 'utc_out'])))
        ->and($pdf['header'])->toContain('Desfase (entrada)', 'Desfase (salida)', 'Desfase (corrección)')
        ->and(FakeReportDocumentRenderer::lastHtml())->toContain('desfase respecto a UTC');
})->group('RF-ID-05', 'RL-05');

it('escribe igual en los dos formatos un estado que el idioma no traduce', function (): void {
    // Antes divergian: el CSV imprimia la clave `personal-record.status.x` y el
    // PDF el valor. Un estado nuevo no puede dejar a nadie sin su registro.
    $journal = new WorkDayJournal(
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        timeZone: PARIDAD_REGISTRO_MADRID,
        range: DateRange::between('2026-03-01', '2026-03-31'),
        days: [new JournalWorkDay('2026-03-14', PARIDAD_REGISTRO_MADRID, null, [
            paridadTramo('t-nuevo', '2026-03-14T07:00:00Z', '2026-03-14T15:00:00Z', 'estado_futuro', 480),
        ], [])],
    );

    $csv = paridadPorColumna(paridadTablaCsv($journal)['rows'][0], PersonalRecordFormat::Csv);
    $pdf = paridadPorColumna(paridadTablaPdf($journal)['rows'][0], PersonalRecordFormat::Pdf);

    expect($csv['status'])->toBe('estado_futuro')
        ->and($pdf['status'])->toBe('estado_futuro');
})->group('RF-ID-05');

it('tiene rotulo en los dos idiomas para cada columna de los dos formatos', function (string $locale): void {
    App::setLocale($locale);

    foreach ([PersonalRecordFormat::Csv, PersonalRecordFormat::Pdf] as $format) {
        expect(PersonalRecordLayout::header($format))->toHaveCount(\count(PersonalRecordLayout::columns($format)))
            ->and(PersonalRecordLayout::criteria($format))->toHaveCount(\count(PersonalRecordLayout::CRITERIA));
    }
})->with(['es', 'en'])->group('RF-ID-05');
