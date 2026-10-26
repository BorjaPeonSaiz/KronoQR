<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Identity\Domain\ValueObject\PrintableCard;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedCardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedInstructionsSheetRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsBusy;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsUnavailable;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;
use App\Modules\Shared\Infrastructure\Adapter\RedisPdfRenderingSlots;
use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Log\NullLogger;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\FakeInstructionsSheetRenderer;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Shared\RecordingPdfRenderingMetrics;
use Tests\Support\Shared\RecordingPdfRenderingSlots;

/*
 * **Un solo tope de Chromium para toda la instalacion**. Las
 * tarjetas y la hoja de instrucciones pasan por el mismo embudo que los
 * informes: con dos huecos, imprimir un lote mientras otras dos personas
 * descargan un PDF no arranca un tercer Chromium.
 *
 * Aqui, sin Redis: el orden de las piezas y la traduccion de fallos. El embudo
 * de verdad, sobre Redis, lo prueban `CredentialPdfConcurrencyTest` y
 * `PdfRenderingConcurrencyTest`.
 */

/** @return list<PrintableCard> */
function pdfSlotsTarjetas(): array
{
    return [new PrintableCard(
        credentialUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        payload: QrPayload::parse('FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'),
        holder: new EmployeeCardProfile(
            employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            employeeCode: 'E7QK2MXPR',
            fullName: 'Persona de prueba',
            siteName: 'Hotel de pruebas',
            siteId: 1,
        ),
    )];
}

it('compone las tarjetas dentro de un hueco del tope, con la espera configurada', function (): void {
    $motor = new FakeCardRenderer;
    $tope = new RecordingPdfRenderingSlots;

    $pdf = (new ConcurrencyLimitedCardRenderer($motor, $tope, 3))->render(pdfSlotsTarjetas(), CardFormat::CARD);

    expect($pdf)->toBe(FakeCardRenderer::PDF_BYTES);
    expect($tope->waits)->toBe([3]);
    expect($motor->formats)->toBe([CardFormat::CARD]);
})->group('RF-QR-04');

it('no toma hueco para un lote vacio', function (): void {
    $tope = new RecordingPdfRenderingSlots(full: true);

    $pdf = (new ConcurrencyLimitedCardRenderer(new FakeCardRenderer, $tope, 3))->render([], CardFormat::SHEET);

    expect($pdf)->toBe(FakeCardRenderer::PDF_BYTES);
    expect($tope->waits)->toBe([]);
})->group('RF-QR-04');

it('con el tope lleno no arranca el motor de las tarjetas', function (): void {
    $motor = new FakeCardRenderer;
    $limitado = new ConcurrencyLimitedCardRenderer($motor, new RecordingPdfRenderingSlots(full: true), 3);

    expect(fn (): string => $limitado->render(pdfSlotsTarjetas(), CardFormat::CARD))
        ->toThrow(PdfRenderingSlotsBusy::class);

    expect($motor->formats)->toBe([]);
})->group('RF-QR-04');

it('compone la hoja de instrucciones dentro de un hueco del tope', function (): void {
    $motor = new FakeInstructionsSheetRenderer;
    $tope = new RecordingPdfRenderingSlots;
    $marca = FixedBranding::product()->current();

    (new ConcurrencyLimitedInstructionsSheetRenderer($motor, $tope, 4))
        ->render('es', $marca, 'https://hotel.example/portal/');

    expect($tope->waits)->toBe([4]);
    expect($motor->renders())->toBe(1);

    $lleno = new ConcurrencyLimitedInstructionsSheetRenderer($motor, new RecordingPdfRenderingSlots(full: true), 4);

    expect(fn (): string => $lleno->render('es', $marca, 'https://hotel.example/portal/'))
        ->toThrow(PdfRenderingSlotsBusy::class);

    expect($motor->renders())->toBe(1);
})->group('RL-05');

it('el rechazo por tope lleno pide al menos un segundo de espera', function (): void {
    expect(PdfRenderingSlotsBusy::allSlotsTaken(0)->retryAfterSeconds)->toBe(1);
    expect(PdfRenderingSlotsBusy::allSlotsTaken(3)->retryAfterSeconds)->toBe(3);
})->group('RF-IN-04');

it('sin Redis falla cerrado, cuenta el rechazo y no arranca ningun Chromium', function (): void {
    $sinRedis = new class implements Redis
    {
        public function connection($name = null): never
        {
            throw new RedisException('Connection refused');
        }
    };
    $metricas = new RecordingPdfRenderingMetrics;
    $arranques = new ArrayObject;

    $tope = new RedisPdfRenderingSlots($sinRedis, new NullLogger, $metricas, 2);

    expect(fn (): string => $tope->compose(function () use ($arranques): string {
        $arranques->append('chromium');

        return '%PDF';
    }, 0))->toThrow(PdfRenderingSlotsUnavailable::class, 'Redis');

    expect($arranques->count())->toBe(0);
    expect($metricas->rejections)->toBe([PdfRenderingMetrics::REASON_LIMITER]);
})->group('RF-IN-04');

it('comparte la clave del embudo con la version anterior, para que una actualizacion no duplique el tope', function (): void {
    expect(RedisPdfRenderingSlots::FUNNEL)->toBe('reporting:pdf-rendering');
})->group('RF-IN-04');
