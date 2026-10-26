<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Reporting\Application\Exception\ReportRenderingUnavailable;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\BrowsershotReportRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\ConcurrencyLimitedReportRenderer;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportDocumentWriters;
use App\Modules\Reporting\Infrastructure\Export\PeriodReportPdfWriter;
use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Metrics\RedisPdfRenderingMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\FakeReportDocumentRenderer;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Tope de composiciones de PDF simultaneas** (bloque 23 de la 2.2.1; BAJO de
 * `seguridad-cumplimiento` en el B8). Cada PDF arranca un Chromium y hasta aqui
 * solo habia limite por tasa: bastaban unas pocas descargas a la vez para
 * llenar la memoria del servidor.
 *
 * El motor sigue siendo el doble de {@see FakeReportDocumentRenderer}; lo que se
 * prueba es el tope de verdad, {@see ConcurrencyLimitedReportRenderer}, sobre
 * Redis de verdad. Cada prueba usa un embudo con nombre propio y sin espera,
 * para no chocar con otra suite que componga PDF a la vez, y ocupa sus huecos
 * escribiendo las mismas claves que escribiria otra composicion en curso.
 *
 * Lo que se defiende:
 *
 * - **Saturado, el PDF responde `503`** con su `type` propio y `Retry-After`,
 *   y el `detail` dice la salida (RF-IN-04).
 * - **El CSV no pasa por el tope** y responde `200` a la vez (RL-05: la
 *   persona siempre puede llevarse su registro).
 * - **La autorizacion va antes que el tope**: quien no puede pedir el
 *   documento recibe `403`, no `503` (regla dura 18).
 */

uses(RefreshDatabase::class);

const PDF_RENDERING_CONCURRENCY_SLOTS = 2;

beforeEach(function (): void {
    LicenseKeys::grantAll();
    Spectator::using('openapi.yaml');
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    App::setLocale('es');
});

/** Un embudo propio de esta prueba, delante del motor de mentira y sin espera. */
function topePdfEmbudo(): string
{
    $funnel = 'testing:pdf-rendering:'.Str::random(12);

    FakeReportDocumentRenderer::bind();

    App::instance(ReportDocumentRenderer::class, new ConcurrencyLimitedReportRenderer(
        new FakeReportDocumentRenderer,
        app(Redis::class),
        app(LoggerInterface::class),
        app(PdfRenderingMetrics::class),
        PDF_RENDERING_CONCURRENCY_SLOTS,
        0,
        $funnel,
    ));

    return $funnel;
}

/** Ocupa todos los huecos del embudo, como lo harian otras composiciones en curso. */
function topePdfSaturar(string $funnel): void
{
    for ($slot = 1; $slot <= PDF_RENDERING_CONCURRENCY_SLOTS; $slot++) {
        app(Redis::class)->connection()->setex($funnel.$slot, 60, 'otra-composicion');
    }
}

function topePdfLiberar(string $funnel): void
{
    for ($slot = 1; $slot <= PDF_RENDERING_CONCURRENCY_SLOTS; $slot++) {
        app(Redis::class)->connection()->del($funnel.$slot);
    }
}

/** @return array{token: string, employee: string} */
function topePdfPortal(): array
{
    $site = WorkforceFixtures::site('Hotel del motor ocupado');
    $employee = WorkforceFixtures::employee($site);
    $token = PortalLogins::open($employee);

    FrozenTime::at('2026-03-20 09:00:00');

    return ['token' => $token, 'employee' => $employee];
}

it('con el motor saturado, el PDF propio responde 503 con su type y Retry-After, y el CSV 200', function (): void {
    $funnel = topePdfEmbudo();
    $contexto = topePdfPortal();
    topePdfSaturar($funnel);

    try {
        Api::as($contexto['token'])
            ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
            ->assertValidRequest()
            ->assertValidResponse(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', ProblemDetails::TYPE_PDF_RENDERING_BUSY)
            ->assertJsonPath('status', 503)
            // La salida escrita: el mismo registro en CSV, que no pasa por el motor.
            ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'CSV'));

        Api::as($contexto['token'])
            ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertValidResponse(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    } finally {
        topePdfLiberar($funnel);
    }

    expect(ProblemDetails::TYPE_PDF_RENDERING_BUSY)->toBe('urn:kronoqr:problem:pdf-rendering-busy');
})->group('RL-05', 'RF-ID-05');

it('con un hueco libre compone el PDF y lo suelta al terminar', function (): void {
    $funnel = topePdfEmbudo();
    $contexto = topePdfPortal();

    // Uno ocupado por otra composicion: queda uno.
    app(Redis::class)->connection()->setex($funnel.'1', 60, 'otra-composicion');

    try {
        Api::as($contexto['token'])
            ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
            ->assertValidResponse(200)
            ->assertHeader('Content-Type', 'application/pdf');

        // El hueco que uso esta descarga ya esta libre; el ajeno sigue ocupado.
        expect(app(Redis::class)->connection()->exists($funnel.'2'))->toBe(0)
            ->and(app(Redis::class)->connection()->exists($funnel.'1'))->toBe(1);
    } finally {
        topePdfLiberar($funnel);
    }
})->group('RL-05', 'RF-ID-05');

it('con el motor saturado, el informe por periodo en PDF responde 503 y en CSV 200', function (): void {
    $funnel = topePdfEmbudo();
    $site = WorkforceFixtures::site('Hotel del informe sin hueco');
    WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    topePdfSaturar($funnel);

    $parametros = ['from' => '2026-03-01', 'to' => '2026-03-07', 'granularity' => 'range'];

    try {
        Api::as($token)
            ->get('/api/v1/reports/period/export', [...$parametros, 'format' => 'pdf'])
            ->assertValidResponse(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', ProblemDetails::TYPE_PDF_RENDERING_BUSY);

        Api::as($token)
            ->get('/api/v1/reports/period/export', [...$parametros, 'format' => 'csv'])
            ->assertOk();
    } finally {
        topePdfLiberar($funnel);
    }
})->group('RF-IN-04');

it('con el motor saturado, el cuadro de impacto en PDF responde 503 y en CSV 200', function (): void {
    $funnel = topePdfEmbudo();
    WorkforceFixtures::site('Hotel del cuadro sin hueco');
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    topePdfSaturar($funnel);

    try {
        Api::as($token)
            ->get('/api/v1/reports/adoption/export?format=pdf&from=2026-03-01&to=2026-03-31')
            ->assertValidResponse(503)
            ->assertJsonPath('type', ProblemDetails::TYPE_PDF_RENDERING_BUSY);

        Api::as($token)
            ->get('/api/v1/reports/adoption/export?format=csv&from=2026-03-01&to=2026-03-31')
            ->assertOk();
    } finally {
        topePdfLiberar($funnel);
    }
})->group('RF-IN-08');

it('la autorizacion va antes que el tope: con el motor saturado, quien no puede pedirlo recibe 403', function (UserRole $rol): void {
    $funnel = topePdfEmbudo();
    WorkforceFixtures::site('Hotel del motor ocupado y la puerta cerrada');
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));
    topePdfSaturar($funnel);

    try {
        // El informe por periodo no es de este rol: 403, nunca el 503 del motor.
        Api::as($token)
            ->get('/api/v1/reports/period/export', ['from' => '2026-03-01', 'to' => '2026-03-07', 'format' => 'pdf'])
            ->assertStatus(403);

        // Y el registro propio es del portal, no de una cuenta de gestion.
        Api::as($token)
            ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
            ->assertStatus(403);
    } finally {
        topePdfLiberar($funnel);
    }
})->with([
    'auditor' => [UserRole::AUDITOR],
    'responsable_departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-IN-04', 'RF-ID-05');

it('sin Redis falla cerrado: 503 de motor no disponible, sin arrancar ningun Chromium', function (): void {
    FakeReportDocumentRenderer::bind();
    $motor = new FakeReportDocumentRenderer;
    $sinRedis = new class implements Redis
    {
        public function connection($name = null): never
        {
            throw new RedisException('Connection refused');
        }
    };

    $tope = new ConcurrencyLimitedReportRenderer($motor, $sinRedis, app(LoggerInterface::class), app(PdfRenderingMetrics::class), 2, 0);

    expect(fn (): string => $tope->renderPdf('<p>x</p>', '<p>pie</p>'))
        ->toThrow(ReportRenderingUnavailable::class, 'Redis');

    // Y el motor no llego a arrancar.
    expect(fn (): string => FakeReportDocumentRenderer::lastHtml())->toThrow(RuntimeException::class);
})->group('RF-IN-04');

it('la instalacion pone el tope delante del motor real, con el limite y la espera de la configuracion', function (): void {
    config()->set('reporting.pdf.max_concurrent', 3);
    config()->set('reporting.pdf.wait_seconds', 4);
    config()->set('reporting.pdf.tagged', false);

    $renderer = app(ReportDocumentRenderer::class);
    $valores = topePdfPropiedades($renderer);

    expect($renderer)->toBeInstanceOf(ConcurrencyLimitedReportRenderer::class)
        ->and($valores['engine'])->toBeInstanceOf(BrowsershotReportRenderer::class)
        ->and($valores['maxConcurrent'])->toBe(3)
        ->and($valores['waitSeconds'])->toBe(4)
        ->and($valores['funnel'])->toBe(ConcurrencyLimitedReportRenderer::FUNNEL);
})->group('RF-IN-04', 'RL-05');

it('suelta el hueco tambien cuando el motor falla', function (): void {
    // Un Chromium que no arranca no puede dejar su hueco ocupado hasta que
    // caduque: dos fallos seguidos bloquearian todos los PDF de la instalacion
    // durante dos minutos.
    $funnel = 'testing:pdf-rendering:'.Str::random(12);
    $tope = new ConcurrencyLimitedReportRenderer(
        new FakeReportDocumentRenderer(fails: true),
        app(Redis::class),
        app(LoggerInterface::class),
        app(PdfRenderingMetrics::class),
        1,
        0,
        $funnel,
    );

    try {
        expect(fn (): string => $tope->renderPdf('<p>x</p>', '<p>pie</p>'))
            ->toThrow(ReportRenderingUnavailable::class, 'Chromium');

        expect(app(Redis::class)->connection()->exists($funnel.'1'))->toBe(0);
    } finally {
        topePdfLiberar($funnel);
    }
})->group('RF-IN-04');

it('el informe en diferido recibe el tope con la espera larga de la cola', function (): void {
    config()->set('reporting.pdf.max_concurrent', 3);
    config()->set('reporting.pdf.wait_seconds', 4);
    config()->set('reporting.pdf.queued_wait_seconds', 240);

    $escritores = app(PeriodReportDocumentWriters::class);
    $pdf = topePdfPropiedades($escritores)['pdf'];
    \assert($pdf instanceof PeriodReportPdfWriter);
    $renderer = topePdfPropiedades($pdf)['renderer'];
    \assert(\is_object($renderer));
    $valores = topePdfPropiedades($renderer);

    expect($renderer)->toBeInstanceOf(ConcurrencyLimitedReportRenderer::class)
        ->and($valores['engine'])->toBeInstanceOf(BrowsershotReportRenderer::class)
        ->and($valores['maxConcurrent'])->toBe(3)
        ->and($valores['waitSeconds'])->toBe(240);
})->group('RF-IN-04');

it('cuenta cada PDF rechazado por el tope en reporting_pdf_rendering_rejected_total', function (): void {
    $funnel = topePdfEmbudo();
    $contexto = topePdfPortal();
    app(Redis::class)->connection()->command('DEL', [RedisPdfRenderingMetrics::PDF_RENDERING_REJECTED_TOTAL]);
    topePdfSaturar($funnel);

    try {
        for ($vez = 0; $vez < 2; $vez++) {
            Api::as($contexto['token'])
                ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
                ->assertStatus(503);
        }

        // El CSV no pasa por el tope: no cuenta.
        Api::as($contexto['token'])->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();
    } finally {
        topePdfLiberar($funnel);
    }

    /** @var array<string, string> $serie */
    $serie = app(Redis::class)->connection()->command('HGETALL', [RedisPdfRenderingMetrics::PDF_RENDERING_REJECTED_TOTAL]);

    // Ninguna etiqueta con datos de nadie (regla dura 21).
    expect($serie)->toBe(['reason=busy' => '2']);
})->group('RF-IN-04');

/**
 * Las propiedades privadas de un objeto, por nombre: el cableado del contenedor
 * no tiene otra forma de leerse sin abrir una API solo para las pruebas.
 *
 * @return array<string, mixed>
 */
function topePdfPropiedades(object $objeto): array
{
    $valores = [];

    foreach ((new ReflectionClass($objeto))->getProperties() as $propiedad) {
        $valores[$propiedad->getName()] = $propiedad->getValue($objeto);
    }

    return $valores;
}
