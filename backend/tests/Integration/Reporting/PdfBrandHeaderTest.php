<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\ReportCriterion;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Reporting\Infrastructure\Export\AdoptionReportPdfWriter;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportPdfWriter;
use App\Modules\Reporting\Infrastructure\Export\PersonalRecordPdfWriter;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Domain\ValueObject\Branding;
use Illuminate\Support\Facades\App;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Product\FixedLogo;
use Tests\Support\Reporting\FakeReportDocumentRenderer;

/*
 * La cabecera de marca de los tres PDF sellados (MB3, DC3; RF-PD-08).
 *
 * - **MB3**: el nombre de la instalacion es texto de 10 pt sobre papel blanco, y
 *   un acento palido lo dejaba a 1,30:1 en un documento con valor probatorio. El
 *   ESCRITOR calcula el acento oscurecido hasta 4,5:1
 *   (`Branding::accentForTextOnWhite()`) y la vista solo lo pinta.
 * - **DC3**: con logotipo, el nombre no salia (`@if ($brandLogo) … @else`). Si
 *   la licencia pierde `white_label`, el logotipo desaparece y dos informes del
 *   mismo mes salian con encabezados distintos. Ahora el nombre sale siempre.
 *
 * Sobre el HTML que recibe el motor ({@see FakeReportDocumentRenderer}): el texto
 * de un PDF de Chromium va codificado y no se puede buscar sin una dependencia
 * nueva. Los tres escritores reales, con la marca y el logotipo inyectados.
 */

beforeEach(function (): void {
    App::setLocale('es');
    FakeReportDocumentRenderer::bind();
});

function pdfBrandHeaderUse(string $accent, bool $withLogo): void
{
    App::instance(BrandingProvider::class, new FixedBranding(new Branding(
        applicationName: 'Hotel Marina',
        logoPath: $withLogo ? '/var/kronoqr/branding/logo.png' : null,
        accentColor: $accent,
    )));
    App::instance(BrandingLogoReader::class, $withLogo ? FixedLogo::png() : FixedLogo::none());
}

function pdfBrandHeaderRange(): DateRange
{
    return DateRange::between('2026-03-01', '2026-03-31');
}

function pdfBrandHeaderGeneratedAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-04-01T05:12:03Z', new DateTimeZone('UTC'));
}

/**
 * Los tres escritores, cada uno con un documento minimo: lo que se mira es la
 * cabecera, no las cifras.
 *
 * @return array<string, array{0: Closure(): string}>
 */
function pdfBrandHeaderWriters(): array
{
    return [
        'informe por periodo' => [static fn (): string => app(PeriodReportPdfWriter::class)->render(
            new PeriodReport(
                rows: [],
                range: pdfBrandHeaderRange(),
                granularity: ReportGranularity::Month,
                grouping: ReportGrouping::Employee,
                timeZone: 'Europe/Madrid',
                generatedAt: pdfBrandHeaderGeneratedAt(),
                criteria: ReportCriterion::listOf(['criteria.source']),
                contractCoverage: new ContractCoverage(0, 0),
            ),
            'Marta Ibáñez',
            str_repeat('a', 64),
        )],
        'cuadro de adopcion' => [static fn (): string => app(AdoptionReportPdfWriter::class)->render(
            new AdoptionReport(
                indicators: [],
                originBreakdown: [],
                range: pdfBrandHeaderRange(),
                previousRange: DateRange::between('2026-02-01', '2026-02-28'),
                timeZone: 'Europe/Madrid',
                generatedAt: pdfBrandHeaderGeneratedAt(),
                criteria: [],
            ),
            'Marta Ibáñez',
            str_repeat('a', 64),
        )],
        'registro personal del portal' => [static fn (): string => app(PersonalRecordPdfWriter::class)->render(
            new WorkDayJournal(
                employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
                timeZone: 'Europe/Madrid',
                range: pdfBrandHeaderRange(),
                days: [],
            ),
            'Lucía Amrani Ruiz',
            str_repeat('a', 64),
            pdfBrandHeaderGeneratedAt(),
        )],
    ];
}

it('pinta el nombre con el acento oscurecido por el escritor hasta 4,5:1 sobre blanco', function (Closure $render): void {
    // `#ffe14d` sobre blanco da 1,30:1; oscurecido, `#867628` da 4,53:1. Es el
    // mismo tono que calcula `HexColor` y el que la vista recibe ya hecho.
    pdfBrandHeaderUse('#ffe14d', withLogo: false);

    $render();
    $html = FakeReportDocumentRenderer::lastHtml();

    expect($html)->toContain('<div class="brand__name" style="color: #867628">Hotel Marina</div>')
        ->and($html)->not->toContain('#ffe14d');
})->with(pdfBrandHeaderWriters())->group('RF-PD-08', 'RF-IN-04');

it('deja intacto un acento que ya se lee', function (Closure $render): void {
    pdfBrandHeaderUse('#0f5c8c', withLogo: false);

    $render();

    expect(FakeReportDocumentRenderer::lastHtml())
        ->toContain('<div class="brand__name" style="color: #0f5c8c">Hotel Marina</div>');
})->with(pdfBrandHeaderWriters())->group('RF-PD-08', 'RF-IN-04');

it('pone el nombre de la instalacion tambien cuando hay logotipo', function (Closure $render): void {
    pdfBrandHeaderUse('#ffe14d', withLogo: true);

    $render();
    $html = FakeReportDocumentRenderer::lastHtml();

    expect($html)->toContain('data:image/png;base64,')
        ->and($html)->toContain('<div class="brand__name" style="color: #867628">Hotel Marina</div>')
        // Con el nombre al lado, el logotipo es decorativo: `alt` vacio para que
        // un lector de pantalla no lea el nombre dos veces.
        ->and($html)->toMatch('/<img src="data:image\/png;base64,[^"]+" alt="">/')
        ->and(substr_count($html, 'Hotel Marina'))->toBe(1);
})->with(pdfBrandHeaderWriters())->group('RF-PD-08', 'RF-IN-04');

it('no calcula el color en la vista', function (string $view): void {
    // La blade solo pinta `$brandAccent`: ni una llamada de color dentro. Sin
    // los comentarios Blade, que si pueden nombrar quien lo calcula.
    $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/pdf/'.$view)));

    expect($blade)->not->toContain('accentForTextOnWhite')
        ->and($blade)->not->toContain('HexColor')
        ->and($blade)->not->toContain('accentColor');
})->with(['period-report.blade.php', 'adoption-report.blade.php'])->group('RF-PD-08');
