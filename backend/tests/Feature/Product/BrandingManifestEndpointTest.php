<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Infrastructure\Adapter\DbBrandingProvider;
use App\Modules\Product\Infrastructure\Adapter\DbLocalePolicyProvider;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\FixedLogo;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/branding/manifest.webmanifest` — el manifiesto del quiosco con la
 * marca de la instalacion (RF-PD-08, RF-PD-01, 2.2.1 PR7).
 *
 * Es lo que hace que, al instalar el quiosco en la tablet, el icono y el nombre
 * de la pantalla de inicio sean los del hotel sin recompilar la PWA por cliente
 * (regla dura 13, ADR-017). Las respuestas se validan contra `openapi.yaml`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
    LicenseKeys::grantAll();
    manifiestoOlvidarMarca();
});

/** Los iconos de serie del quiosco, los de `frontend-kiosk/vite.config.ts`. */
const BRANDING_MANIFEST_PRODUCT_ICONS = [
    ['src' => '/kiosk/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => '/kiosk/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => '/kiosk/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
];

function manifiestoOlvidarMarca(): void
{
    foreach ([
        BrandingProvider::class,
        BrandingLogoReader::class,
        LocalePolicyProvider::class,
        DbBrandingProvider::class,
        DbLocalePolicyProvider::class,
        FeatureGate::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

/**
 * Guarda la marca por la via del panel, con un logotipo de esos bytes si se da.
 *
 * @param  array<string, mixed>  $settings
 */
function manifiestoGuardarMarca(array $settings, ?string $logoBytes = null, string $logoName = 'logo.png'): void
{
    if ($logoBytes !== null) {
        $root = sys_get_temp_dir().'/kronoqr-manifest-'.bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        config(['branding.logo_root' => $root]);
        file_put_contents($root.'/'.$logoName, $logoBytes);
        $settings['BRANDING_LOGO_PATH'] = $root.'/'.$logoName;
    }

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => $settings])
        ->assertValidResponse(200);

    manifiestoOlvidarMarca();
}

/** @return TestResponse<Response> */
function manifiestoPedir(): TestResponse
{
    $response = Api::guest()->get('/api/v1/branding/manifest.webmanifest')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->headers->get('Content-Type'))->toStartWith('application/manifest+json');

    return $response;
}

it('sirve el manifiesto de serie, sin autenticar, cuando no hay marca', function (): void {
    DB::table('installation_settings')->delete();
    manifiestoOlvidarMarca();

    $response = manifiestoPedir();

    expect($response->json())->toBe([
        'id' => '/kiosk/',
        'name' => 'KronoQR',
        'short_name' => 'KronoQR',
        'description' => 'Registro horario por QR',
        'lang' => 'es',
        'start_url' => '/kiosk/',
        'scope' => '/kiosk/',
        'display' => 'fullscreen',
        'display_override' => ['fullscreen', 'standalone'],
        'theme_color' => '#241a15',
        'background_color' => '#241a15',
        'icons' => BRANDING_MANIFEST_PRODUCT_ICONS,
    ]);
})->group('RF-PD-01', 'RF-PD-08');

it('publica el nombre, el color y el logotipo PNG cuadrado con su tamaño real', function (int $side): void {
    $bytes = FixedLogo::pngOfSize($side, $side);

    manifiestoGuardarMarca(
        ['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_ACCENT_COLOR' => '#0f5c8c'],
        $bytes,
    );

    $response = manifiestoPedir();
    $logoUrl = '/api/v1/branding/logo?v='.substr(hash('sha256', $bytes), 0, 12);

    expect($response->json('name'))->toBe('Hotel Marina')
        ->and($response->json('short_name'))->toBe('Hotel Marina')
        ->and($response->json('theme_color'))->toBe('#241a15')
        // Lo que es comportamiento del quiosco no se mueve con la marca.
        ->and($response->json('id'))->toBe('/kiosk/')
        ->and($response->json('background_color'))->toBe('#241a15')
        ->and($response->json())->not->toHaveKey('orientation')
        // La misma URL con huella que publica `GET /api/v1/branding`, en una
        // sola entrada y con el tamaño que mide de verdad: declararla a 192 o a
        // 512 sin medirlo hace que Chromium avise de un manifiesto incoherente.
        ->and($response->json('icons'))->toBe([
            ['src' => $logoUrl, 'sizes' => $side.'x'.$side, 'type' => 'image/png', 'purpose' => 'any'],
        ])
        ->and(Api::guest()->get('/api/v1/branding')->json('logo_url'))->toBe($logoUrl);
})->with(['512 px' => 512, '1024 px' => 1024])->group('RF-PD-01', 'RF-PD-08');

it('mantiene los iconos de serie si el logotipo no sirve de icono', function (string $logoName, string $bytes): void {
    // Sin rasterizar nada: un SVG, un PNG pequeño o uno no cuadrado deformarian
    // el icono de la pantalla de inicio. El nombre y el color si se publican.
    manifiestoGuardarMarca(
        ['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_ACCENT_COLOR' => '#0f5c8c'],
        $bytes,
        $logoName,
    );

    // El logotipo es valido como marca: `GET /branding` si lo publica.
    expect(Api::guest()->get('/api/v1/branding')->json('logo_url'))->toBeString();
    manifiestoOlvidarMarca();

    $response = manifiestoPedir();

    expect($response->json('icons'))->toBe(BRANDING_MANIFEST_PRODUCT_ICONS)
        ->and($response->json('name'))->toBe('Hotel Marina')
        ->and($response->json('theme_color'))->toBe('#241a15');
})->with([
    'SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"></svg>'],
    'PNG de 1 px' => ['logo.png', FixedLogo::onePixelPng()],
    'PNG cuadrado de 256' => ['logo.png', FixedLogo::pngOfSize(256, 256)],
    'PNG no cuadrado' => ['logo.png', FixedLogo::pngOfSize(1024, 512)],
])->group('RF-PD-01', 'RF-PD-08');

it('sin la marca blanca en el plan, conserva el nombre pero no el color ni el logotipo', function (): void {
    manifiestoGuardarMarca(
        ['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_ACCENT_COLOR' => '#0f5c8c'],
        FixedLogo::pngOfSize(512, 512),
    );

    FrozenTime::at('2026-06-15 09:00:00');
    app(ActivateLicenseHandler::class)->handle(
        new ActivateLicenseCommand(LicenseKeys::current()->issue(['features' => ['advanced_reports']])),
    );
    manifiestoOlvidarMarca();

    $response = manifiestoPedir();

    // ADR-023: el mismo decorador que `GET /branding`. El nombre identifica al
    // obligado y no lo degrada la licencia (regla dura 15).
    expect($response->json('name'))->toBe('Hotel Marina')
        ->and($response->json('theme_color'))->toBe('#241a15')
        ->and($response->json('icons'))->toBe(BRANDING_MANIFEST_PRODUCT_ICONS);
})->group('RF-PD-08', 'RF-PD-05');

it('declara el idioma por defecto de la instalacion y describe en el', function (): void {
    manifiestoGuardarMarca(['LOCALE_DEFAULT' => 'en', 'LOCALE_AVAILABLE' => ['es', 'en']]);

    $response = manifiestoPedir();

    expect($response->json('lang'))->toBe('en')
        ->and($response->json('description'))->toBe('QR time tracking');
})->group('RF-PD-01', 'RF-PD-08');

it('comparte el techo por origen de la marca y responde 429 al superarlo', function (): void {
    config(['product.branding_rate_limit_per_minute' => 3]);

    for ($i = 0; $i < 3; $i++) {
        Api::guest()->fromIp('203.0.113.7')->get('/api/v1/branding/manifest.webmanifest')->assertStatus(200);
    }

    Api::guest()->fromIp('203.0.113.7')->get('/api/v1/branding/manifest.webmanifest')->assertValidResponse(429);

    Api::guest()->fromIp('203.0.113.8')->get('/api/v1/branding/manifest.webmanifest')->assertStatus(200);
})->group('RF-PD-08');
