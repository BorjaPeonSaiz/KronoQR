<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\PersonalRecordHolderDirectory;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Domain\ValueObject\CorrectionAuthor;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\JournalCorrection;
use App\Modules\Reporting\Domain\ValueObject\JournalShiftEntry;
use App\Modules\Reporting\Domain\ValueObject\JournalWorkDay;
use App\Modules\Reporting\Domain\ValueObject\ShiftMarks;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Infrastructure\Adapter\BrowsershotReportRenderer;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordDigest;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordLayout;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordPdfWriter;
use Illuminate\Support\Facades\App;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\FakeReportDocumentRenderer;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El PDF sellado del registro propio** (PR19, RF-ID-05, RL-05; Anexo B del
 * doc 01): se genera de verdad, va sellado y lleva los tramos y el total del
 * periodo.
 *
 * El mismo reparto que `PeriodReportPdfSealTest`, y por los mismos motivos: el
 * PDF real —con Chromium— demuestra que **el sello cambia el documento**; el
 * HTML que se le entrega al motor —con {@see FakeReportDocumentRenderer}—
 * demuestra **que dice**, porque el texto de dentro de un PDF de Chromium va
 * codificado contra un CMap propio y no se puede buscar sin un extractor que
 * seria una dependencia nueva.
 *
 * El registro se construye a mano para poder **cambiar un minuto y solo uno** y
 * comprobar que la huella se mueve.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    App::setLocale('es');
});

const PERSONAL_RECORD_PDF_SEAL_EMPLOYEE = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90';

/**
 * Dos jornadas de marzo: un turno de mañana de ocho horas y un turno de noche
 * de 22:00 a 06:00 —un solo tramo, regla dura 4— corregido por RRHH.
 */
function registroPersonalSellable(int $nightMinutes = 480): WorkDayJournal
{
    $utc = new DateTimeZone('UTC');
    $madrid = 'Europe/Madrid';

    $morning = new JournalShiftEntry(
        uuid: '0199f0c2-0000-7000-8000-000000000001',
        version: 1,
        status: 'closed',
        siteId: 1,
        timeZone: $madrid,
        clockedInAt: new DateTimeImmutable('2026-03-14T05:00:00Z', $utc),
        clockInRecordedAt: new DateTimeImmutable('2026-03-14T05:00:01Z', $utc),
        clockInSource: 'qr_kiosk',
        clockedOutAt: new DateTimeImmutable('2026-03-14T13:00:00Z', $utc),
        clockOutRecordedAt: new DateTimeImmutable('2026-03-14T13:00:01Z', $utc),
        clockOutSource: 'qr_kiosk',
        durationMinutes: 480,
        recordedAt: new DateTimeImmutable('2026-03-14T13:00:01Z', $utc),
        closedBy: JournalShiftEntry::CLOSED_BY_CLOCK_OUT,
    );

    $night = new JournalShiftEntry(
        uuid: '0199f0c2-0000-7000-8000-000000000002',
        version: 2,
        status: 'closed',
        siteId: 1,
        timeZone: $madrid,
        clockedInAt: new DateTimeImmutable('2026-03-15T21:00:00Z', $utc),
        clockInRecordedAt: new DateTimeImmutable('2026-03-15T21:00:01Z', $utc),
        clockInSource: 'qr_kiosk',
        clockedOutAt: new DateTimeImmutable('2026-03-16T05:00:00Z', $utc),
        clockOutRecordedAt: null,
        clockOutSource: 'manual',
        durationMinutes: $nightMinutes,
        recordedAt: new DateTimeImmutable('2026-03-16T09:00:00Z', $utc),
        closedBy: JournalShiftEntry::CLOSED_BY_CLOCK_OUT,
    );

    $correction = new JournalCorrection(
        shiftEntryUuid: $night->uuid,
        action: 'closed',
        performedAt: new DateTimeImmutable('2026-03-16T09:00:00Z', $utc),
        performedBy: new CorrectionAuthor('0199f0c2-0000-7000-8000-0000000000aa', 'Marta Ibáñez'),
        reasonCode: 'OLVIDO_FICHAJE_SALIDA',
        reasonText: 'La persona confirma la salida a las 06:00 con el parte de recepción.',
        before: new ShiftMarks(1, $night->clockedInAt, null, 0),
        after: new ShiftMarks(2, $night->clockedInAt, $night->clockedOutAt, $nightMinutes),
    );

    return new WorkDayJournal(
        employeeUuid: PERSONAL_RECORD_PDF_SEAL_EMPLOYEE,
        timeZone: $madrid,
        range: DateRange::between('2026-03-01', '2026-03-31'),
        days: [
            new JournalWorkDay('2026-03-14', $madrid, null, [$morning], []),
            new JournalWorkDay('2026-03-15', $madrid, null, [$night], [$correction]),
        ],
    );
}

/**
 * El binario que de verdad se va a usar: el de puppeteer en la CI, el de la
 * distribucion en la imagen del producto.
 */
function registroPersonalHayChromium(): bool
{
    $configured = getenv('LARAVEL_PDF_CHROME_PATH');

    if (is_string($configured) && $configured !== '') {
        return is_executable($configured);
    }

    return is_executable('/usr/bin/chromium') || is_executable('/usr/bin/chromium-browser');
}

function registroPersonalPdf(WorkDayJournal $registro, string $huella): string
{
    return app(PersonalRecordPdfWriter::class)->render(
        $registro,
        'Lucía Amrani Ruiz',
        $huella,
        new DateTimeImmutable('2026-04-01T05:12:03Z', new DateTimeZone('UTC')),
    );
}

it('compone un PDF de verdad, y el sello forma parte del documento', function (): void {
    // Si el sello no llegara al papel, los dos ficheros serian identicos.
    $registro = registroPersonalSellable();
    $huella = PersonalRecordDigest::of($registro)->toText();

    $conSuHuella = registroPersonalPdf($registro, $huella);
    $conOtraHuella = registroPersonalPdf($registro, str_repeat('a', 64));

    expect($conSuHuella)->toStartWith('%PDF-')
        ->and(strlen($conSuHuella))->toBeGreaterThan(2000)
        ->and($conOtraHuella)->not->toBe($conSuHuella);
})->group('RF-ID-05', 'RL-05')->skip(! registroPersonalHayChromium(), 'Chromium no esta instalado en este contenedor.');

it('usa el motor real y no el doble de las pruebas de feature', function (): void {
    // Control: sin esto, el caso anterior pasaria con un doble enlazado por otra
    // prueba de la suite y no estaria comprobando ningun PDF.
    expect(app(ReportDocumentRenderer::class))->toBeInstanceOf(BrowsershotReportRenderer::class);
})->group('RF-ID-05');

it('repite en cada pagina el sello con fecha local, persona, periodo y huella', function (): void {
    FakeReportDocumentRenderer::bind();

    $registro = registroPersonalSellable();
    $huella = PersonalRecordDigest::of($registro)->toText();

    registroPersonalPdf($registro, $huella);

    $pie = FakeReportDocumentRenderer::lastFooter();

    // Es la plantilla del sello del informe por periodo, no una propia.
    expect($pie)->toContain('pageNumber');
    // 07:12 de Madrid, no 05:12 de UTC (regla dura 3, ADR-040).
    expect($pie)->toContain('2026-04-01 07:12');
    expect($pie)->toContain('Europe/Madrid');
    expect($pie)->toContain('Descargado por: Lucía Amrani Ruiz');
    expect($pie)->toContain('2026-03-01 → 2026-03-31');
    expect($pie)->toContain($huella);
})->group('RF-ID-05', 'RL-05');

it('lleva los tramos, el turno de noche entero, la correccion y el total del periodo', function (): void {
    FakeReportDocumentRenderer::bind();

    $registro = registroPersonalSellable();

    registroPersonalPdf($registro, PersonalRecordDigest::of($registro)->toText());

    $html = FakeReportDocumentRenderer::lastHtml();

    expect($html)->toContain('Lucía Amrani Ruiz');
    expect($html)->toContain('2026-03-14 06:00');
    expect($html)->toContain('2026-03-14 14:00');
    // El turno de noche es UNA fila, de las 22:00 a las 06:00 del dia siguiente,
    // en la jornada en la que empezo (RN-05, regla dura 4).
    expect($html)->toContain('2026-03-15 22:00');
    expect($html)->toContain('2026-03-16 06:00');
    // La correccion, con su autor, su motivo y su explicacion (RN-13).
    expect($html)->toContain('Marta Ibáñez');
    expect($html)->toContain('OLVIDO_FICHAJE_SALIDA');
    expect($html)->toContain('parte de recepción');
    // Las columnas de texto libre se pueden partir; las horas no.
    expect($html)->toContain('class="wrap"');
    // 08:00 + 08:00 = 16:00, en `HH:MM` y nunca decimal.
    expect($html)->toContain('Total del periodo');
    expect($html)->toContain('16:00');
    expect($html)->not->toContain('16,0');
    expect(PersonalRecordLayout::totalMinutes($registro))->toBe(960);
    expect(PersonalRecordLayout::rows($registro))->toHaveCount(3);
})->group('RF-ID-05', 'RL-05', 'RN-05', 'RN-13');

it('cambia la huella cuando cambia un solo minuto del registro', function (): void {
    $original = PersonalRecordDigest::of(registroPersonalSellable());
    $corregido = PersonalRecordDigest::of(registroPersonalSellable(481));

    expect($original->toText())->toMatch('/^[0-9a-f]{64}$/')
        ->and($corregido->toText())->not->toBe($original->toText());
})->group('RF-ID-05');

it('da la misma huella para el mismo contenido y en cualquier idioma', function (): void {
    // La huella es del CONTENIDO: ni el sello temporal ni el idioma entran, asi
    // que dos copias del mismo registro se pueden comparar.
    $enEspanol = PersonalRecordDigest::of(registroPersonalSellable())->toText();

    App::setLocale('en');

    expect(PersonalRecordDigest::of(registroPersonalSellable())->toText())->toBe($enEspanol);
})->group('RF-ID-05');

it('resuelve el nombre del titular desde la ficha, sin el modelo de Workforce', function (): void {
    $site = WorkforceFixtures::site('Hotel del titular');
    $employee = WorkforceFixtures::employee($site, null, 'active', 'Lucía', 'Amrani Ruiz');

    $directory = app(PersonalRecordHolderDirectory::class);

    expect($directory->fullNameOf($employee))->toBe('Lucía Amrani Ruiz')
        // Sin ficha, `null`, y el documento sale igual con el rotulo de persona
        // no identificable.
        ->and($directory->fullNameOf('0199f0c2-ffff-7fff-bfff-ffffffffffff'))->toBeNull();
})->group('RF-ID-05');
