<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Identity\Infrastructure\Adapter\BrowsershotCardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\BrowsershotInstructionsSheetRenderer;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedCardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedInstructionsSheetRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Adapter\RedisPdfRenderingSlots;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\FakeInstructionsSheetRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Las tarjetas y la hoja de instrucciones pasan por el tope de Chromium de
 * toda la instalacion**. Antes solo lo cruzaban los informes, y
 * `config/reporting.php` prometia un tope «para todas las rutas»: imprimir un
 * lote mientras otras dos personas descargaban un PDF arrancaba un tercer
 * Chromium con el tope en 2.
 *
 * El motor es el doble de siempre; el tope es el de verdad
 * ({@see RedisPdfRenderingSlots}) sobre Redis de verdad, con un embudo propio y
 * sin espera para no chocar con otra suite.
 *
 * Lo que se defiende:
 *
 * - **Saturado, imprimir responde `503 pdf-rendering-busy`** con `Retry-After`,
 *   como el contrato (`CredentialDocumentUnavailable`).
 * - **Ninguna credencial queda impresa** por un rechazo del tope: el PDF va
 *   antes de la transaccion, y en cuanto hay hueco se imprime.
 * - **La autorizacion va antes que el tope**: quien no gestiona credenciales
 *   recibe `403`, no `503` (regla dura 18).
 */

uses(RefreshDatabase::class);

const CREDENTIAL_PDF_CONCURRENCY_SLOTS = 2;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/** Un embudo propio, delante de los dos motores de mentira y sin espera. */
function topeTarjetasEmbudo(): string
{
    $funnel = 'testing:pdf-rendering:'.Str::random(12);
    $slots = new RedisPdfRenderingSlots(
        app(Redis::class),
        app(LoggerInterface::class),
        app(PdfRenderingMetrics::class),
        CREDENTIAL_PDF_CONCURRENCY_SLOTS,
        $funnel,
    );

    app()->instance(CardRenderer::class, new ConcurrencyLimitedCardRenderer(new FakeCardRenderer, $slots, 0));
    app()->instance(
        InstructionsSheetRenderer::class,
        new ConcurrencyLimitedInstructionsSheetRenderer(new FakeInstructionsSheetRenderer, $slots, 0),
    );

    return $funnel;
}

function topeTarjetasSaturar(string $funnel): void
{
    for ($slot = 1; $slot <= CREDENTIAL_PDF_CONCURRENCY_SLOTS; $slot++) {
        app(Redis::class)->connection()->setex($funnel.$slot, 60, 'otra-composicion');
    }
}

function topeTarjetasLiberar(string $funnel): void
{
    for ($slot = 1; $slot <= CREDENTIAL_PDF_CONCURRENCY_SLOTS; $slot++) {
        app(Redis::class)->connection()->del($funnel.$slot);
    }
}

function topeTarjetasRrhh(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

function topeTarjetasPendiente(string $token): string
{
    $uuid = Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => WorkforceFixtures::employee(WorkforceFixtures::site())])
        ->assertStatus(201)
        ->json('uuid');

    expect($uuid)->toBeString();

    /** @var string $uuid */
    return $uuid;
}

it('con el motor saturado, imprimir una tarjeta responde 503 con Retry-After y no la marca impresa', function (): void {
    $funnel = topeTarjetasEmbudo();
    $token = topeTarjetasRrhh();
    $credencial = topeTarjetasPendiente($token);
    topeTarjetasSaturar($funnel);

    try {
        Api::as($token)->post('/api/v1/credentials/'.$credencial.'/print')
            ->assertValidRequest()
            ->assertValidResponse(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', ProblemDetails::TYPE_PDF_RENDERING_BUSY);

        expect(DB::table('credentials')->where('uuid', $credencial)->value('printed_at'))->toBeNull();
    } finally {
        topeTarjetasLiberar($funnel);
    }

    // Con hueco, la misma tarjeta se imprime: el rechazo no la ha gastado.
    Api::as($token)->post('/api/v1/credentials/'.$credencial.'/print')
        ->assertValidResponse(200);
})->group('RF-QR-04', 'RF-IN-04');

it('con el motor saturado, el lote responde 503 y la hoja de instrucciones tambien', function (): void {
    $funnel = topeTarjetasEmbudo();
    $token = topeTarjetasRrhh();
    topeTarjetasPendiente($token);
    topeTarjetasSaturar($funnel);

    try {
        Api::as($token)->post('/api/v1/credentials/print-batch')
            ->assertValidResponse(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', ProblemDetails::TYPE_PDF_RENDERING_BUSY);

        Api::as($token)->get('/api/v1/credentials/instructions-sheet')
            ->assertValidRequest()
            ->assertValidResponse(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', ProblemDetails::TYPE_PDF_RENDERING_BUSY);
    } finally {
        topeTarjetasLiberar($funnel);
    }
})->group('RF-QR-04', 'RL-05', 'RF-IN-04');

it('la autorizacion va antes que el tope: quien no gestiona credenciales recibe 403', function (UserRole $rol): void {
    $funnel = topeTarjetasEmbudo();
    $credencial = topeTarjetasPendiente(topeTarjetasRrhh());
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));
    topeTarjetasSaturar($funnel);

    try {
        Api::as($token)->post('/api/v1/credentials/'.$credencial.'/print')->assertStatus(403);
        Api::as($token)->post('/api/v1/credentials/print-batch')->assertStatus(403);
        Api::as($token)->get('/api/v1/credentials/instructions-sheet')->assertStatus(403);
    } finally {
        topeTarjetasLiberar($funnel);
    }
})->with([
    'auditor' => [UserRole::AUDITOR],
    'responsable_departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-QR-04', 'RL-05');

it('la instalacion pone el tope de Chromium delante de los motores reales de tarjetas y hoja', function (): void {
    $leer = static function (object $objeto, string $propiedad): mixed {
        return (new ReflectionProperty($objeto, $propiedad))->getValue($objeto);
    };

    config()->set('reporting.pdf.wait_seconds', 4);

    $tarjetas = app(CardRenderer::class);
    $hoja = app(InstructionsSheetRenderer::class);

    expect($tarjetas)->toBeInstanceOf(ConcurrencyLimitedCardRenderer::class)
        ->and($leer($tarjetas, 'engine'))->toBeInstanceOf(BrowsershotCardRenderer::class)
        ->and($leer($tarjetas, 'slots'))->toBeInstanceOf(PdfRenderingSlots::class)
        ->and($leer($tarjetas, 'waitSeconds'))->toBe(4)
        ->and($hoja)->toBeInstanceOf(ConcurrencyLimitedInstructionsSheetRenderer::class)
        ->and($leer($hoja, 'engine'))->toBeInstanceOf(BrowsershotInstructionsSheetRenderer::class)
        ->and($leer($hoja, 'waitSeconds'))->toBe(4)
        ->and(app(PdfRenderingSlots::class))->toBeInstanceOf(RedisPdfRenderingSlots::class);
})->group('RF-QR-04', 'RL-05');
