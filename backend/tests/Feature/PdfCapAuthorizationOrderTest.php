<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedCardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedInstructionsSheetRenderer;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\ConcurrencyLimitedReportRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use App\Modules\Shared\Infrastructure\Adapter\RedisPdfRenderingSlots;
use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Log\LoggerInterface;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Http\AuthorizationMatrix;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\FakeInstructionsSheetRenderer;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\FakeReportDocumentRenderer;

/*
 * «La autorizacion va antes que el tope de PDF», en TODAS las rutas que
 * componen un PDF y con TODOS los actores que la matriz deniega (V21-4-QA-2 de
 * la verificacion de la 2.2.1).
 *
 * Con el motor de Chromium saturado, quien no puede pedir el documento tiene
 * que recibir su `403` (o el `401` que la matriz fija), nunca el `503` del
 * motor: un `503` le diria que la ruta existe para el y que solo hay que
 * esperar, y gastaria un hueco del motor en alguien que no tenia derecho a el
 * (regla dura 18). `PdfRenderingConcurrencyTest` y
 * `CredentialPdfConcurrencyTest` lo prueban con dos roles; aqui va la matriz
 * entera, sin token incluido.
 *
 * Las seis rutas son las que responden `application/pdf` en el contrato y
 * piden sesion: la descarga diferida (`reports/exports/{uuid}/download`) es
 * publica por enlace firmado (ADR-041) y la compone la cola, no la peticion.
 */

uses(RefreshDatabase::class);

/** Huecos del embudo de esta prueba: uno basta, y se ocupa entero. */
const PDF_CAP_AUTHORIZATION_SLOTS = 1;

beforeEach(function (): void {
    LicenseKeys::grantAll();
    config()->set('identity.portal.rate_limit_per_minute', 10_000);

    $funnel = pdfCapAuthorizationFunnel();
    $slots = new RedisPdfRenderingSlots(
        app(Redis::class),
        app(LoggerInterface::class),
        app(PdfRenderingMetrics::class),
        PDF_CAP_AUTHORIZATION_SLOTS,
        $funnel,
    );

    FakeReportDocumentRenderer::bind();
    app()->instance(ReportDocumentRenderer::class, new ConcurrencyLimitedReportRenderer(new FakeReportDocumentRenderer, $slots, 0));
    app()->instance(CardRenderer::class, new ConcurrencyLimitedCardRenderer(new FakeCardRenderer, $slots, 0));
    app()->instance(InstructionsSheetRenderer::class, new ConcurrencyLimitedInstructionsSheetRenderer(new FakeInstructionsSheetRenderer, $slots, 0));

    // El motor, ocupado por otra composicion en curso.
    app(Redis::class)->connection()->setex($funnel.'1', 60, 'otra-composicion');
});

afterEach(function (): void {
    app(Redis::class)->connection()->del(pdfCapAuthorizationFunnel().'1');
});

/**
 * El embudo propio de esta prueba: uno por proceso, para que dos pasadas en
 * paralelo no se ocupen el hueco la una a la otra.
 */
function pdfCapAuthorizationFunnel(): string
{
    return 'testing:pdf-cap-authorization:'.getmypid().':';
}

/**
 * Las rutas con sesion que componen un PDF, con la consulta que lo pide.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function pdfCapAuthorizationRoutes(): array
{
    return [
        'mi registro' => ['GET api/v1/me/export', '?from=2026-03-01&to=2026-03-31&format=pdf'],
        'informe por periodo' => ['GET api/v1/reports/period/export', '?from=2026-03-01&to=2026-03-07&format=pdf'],
        'cuadro de impacto' => ['GET api/v1/reports/adoption/export', '?from=2026-03-01&to=2026-03-31&format=pdf'],
        'tarjeta' => ['POST api/v1/credentials/{uuid}/print', ''],
        'lote de tarjetas' => ['POST api/v1/credentials/print-batch', ''],
        'hoja de instrucciones' => ['GET api/v1/credentials/instructions-sheet', ''],
    ];
}

/**
 * Lo que la ruta responde distinto de la denegacion que la matriz espera, con
 * el motor saturado: vacio si todo cuadra.
 *
 * @return list<string>
 */
function pdfCapAuthorizationDiscrepanciesOf(string $route, string $query): array
{
    $installation = AuthorizationMatrix::installation();
    $unauthenticated = AuthorizationMatrix::unauthenticatedDenials()[$route] ?? [];
    [$method, $uri] = AuthorizationMatrix::requestOf($route, $installation);
    $found = [];

    $guest = Api::guest()->call($method, $uri.$query)->getStatusCode();
    $found[] = $guest === 401 ? null : sprintf('%s · sin token: esperado 401, recibido %d', $route, $guest);

    foreach (array_diff(AuthorizationMatrix::actors(), AuthorizationMatrix::routes()[$route]) as $actor) {
        $expected = \in_array($actor, $unauthenticated, true) ? 401 : 403;
        $token = AuthorizationMatrix::tokenFor($actor, $installation);
        $status = Api::as($token)->call($method, $uri.$query)->getStatusCode();
        $found[] = $status === $expected ? null : sprintf('%s · %s: esperado %d, recibido %d', $route, $actor, $expected, $status);
    }

    return array_values(array_filter($found));
}

it('con el motor saturado, deniega cada ruta de PDF a cada actor que la matriz no autoriza', function (string $route, string $query): void {
    $discrepancies = pdfCapAuthorizationDiscrepanciesOf($route, $query);

    expect($discrepancies)->toBe([], implode("\n", $discrepancies));
})->with(pdfCapAuthorizationRoutes())->group('RQ-07', 'RF-IN-04', 'RF-QR-04', 'RF-ID-05');

it('con el motor saturado, a quien si puede pedir el PDF le responde 503', function (string $route, string $query, string $actor): void {
    // El control: sin el, un embudo mal montado —que no estuviera saturado, o
    // que no estuviera delante del motor— haria pasar la prueba de arriba sin
    // que el tope interviniera en nada.
    $installation = AuthorizationMatrix::installation();
    [$method, $uri] = AuthorizationMatrix::requestOf($route, $installation);

    Api::as(AuthorizationMatrix::tokenFor($actor, $installation))
        ->call($method, $uri.$query)
        ->assertStatus(503);
})->with([
    'mi registro, con la sesion de portal' => ['GET api/v1/me/export', '?from=2026-03-01&to=2026-03-31&format=pdf', AuthorizationMatrix::EMPLEADO],
    'informe por periodo, con rrhh' => ['GET api/v1/reports/period/export', '?from=2026-03-01&to=2026-03-07&format=pdf', AuthorizationMatrix::RRHH],
    'hoja de instrucciones, con rrhh' => ['GET api/v1/credentials/instructions-sheet', '', AuthorizationMatrix::RRHH],
])->group('RQ-07', 'RF-IN-04', 'RF-QR-04');

it('recorre todas las rutas con sesion que el contrato sirve en PDF', function (): void {
    // Si el contrato gana una ruta que responde `application/pdf`, tiene que
    // entrar en la lista de arriba. La descarga diferida es publica (ADR-041).
    $contract = (string) file_get_contents(base_path('../docs/api/openapi.yaml'));
    preg_match_all('#^  (/api/v1/\S+):\n(?:(?!^  /).*\n)*?\s+application/pdf:#m', $contract, $matches);

    $withPdf = array_values(array_diff($matches[1], ['/api/v1/reports/exports/{uuid}/download']));
    sort($withPdf);

    $covered = array_map(
        static fn (array $route): string => '/'.explode(' ', $route[0], 2)[1],
        array_values(pdfCapAuthorizationRoutes()),
    );
    sort($covered);

    expect($withPdf)->toBe($covered);
})->group('RQ-07');
