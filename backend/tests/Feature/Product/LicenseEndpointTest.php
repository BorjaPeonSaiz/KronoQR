<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Support\Health\LicenseStateProbe;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/license` y `POST /api/v1/license/activate` (RF-PD-04, Anexo B).
 *
 * Cada respuesta se valida contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013).
 *
 * El reloj esta detenido (regla dura 2): sin eso, `days_until_expiry` cambiaria
 * en cada ejecucion y la mitad de estas aserciones serian imposibles.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();

    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
});

function licenseAdminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('sin licencia devuelve 200 con estado «absent», nunca 404', function (): void {
    // «Sin licencia» es un ESTADO del recurso, no la ausencia del recurso: es el
    // caso mas comun de una puesta en marcha, y un 404 obligaria al panel a
    // tratarlo como un error indistinguible de una ruta mal escrita.
    $response = Api::as(licenseAdminToken())->get('/api/v1/license')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.state'))->toBe('absent')
        ->and($response->json('data.customer_name'))->toBeNull()
        ->and($response->json('data.key_fingerprint'))->toBeNull()
        ->and($response->json('meta.needs_notice'))->toBeTrue()
        ->and($response->json('meta.expiry_warning_days'))->toBe(30)
        // Las cifras reales se dan igual, aunque no haya con que compararlas.
        ->and($response->json('data.limits.0.limit'))->toBe('max_employees')
        ->and($response->json('data.limits.0.contracted'))->toBeNull()
        ->and($response->json('data.limits.0.actual'))->toBe(0);
})->group('RF-PD-04');

it('activa una clave y devuelve el estado completo', function (): void {
    $response = Api::as(licenseAdminToken())
        ->post('/api/v1/license/activate', ['signed_key' => LicenseKeys::current()->issue()])
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.state'))->toBe('valid')
        ->and($response->json('data.severity'))->toBe('none')
        ->and($response->json('data.customer_name'))->toBe('Hotel de Pruebas, S.L.')
        ->and($response->json('data.plan'))->toBe('estandar')
        ->and($response->json('data.features'))->toBe(['advanced_reports', 'realtime_presence'])
        ->and($response->json('data.days_until_expiry'))->toBe(199)
        ->and($response->json('data.days_since_expiry'))->toBeNull()
        ->and($response->json('meta.needs_notice'))->toBeFalse();
})->group('RF-PD-04');

it('nunca devuelve la clave firmada, solo su huella', function (): void {
    $key = LicenseKeys::current()->issue();

    $response = Api::as(licenseAdminToken())->post('/api/v1/license/activate', ['signed_key' => $key]);

    expect($response->json('data.key_fingerprint'))->toMatch('/^[0-9a-f]{12}$/')
        ->and($response->getContent())->not->toContain($key)
        ->and($response->getContent())->not->toContain('KQL1.');

    expect(Api::as(licenseAdminToken())->get('/api/v1/license')->getContent())->not->toContain('KQL1.');
})->group('RF-PD-04');

it('enseña lo degradado con su motivo y su fecha', function (): void {
    Api::as(licenseAdminToken())->post('/api/v1/license/activate', ['signed_key' => LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])]);

    $response = Api::as(licenseAdminToken())->get('/api/v1/license')->assertValidResponse(200);

    expect($response->json('data.state'))->toBe('expired')
        ->and($response->json('data.severity'))->toBe('critical')
        ->and($response->json('data.days_since_expiry'))->toBe(165);

    /** @var list<array<string, mixed>> $degraded */
    $degraded = $response->json('data.degraded_features');
    $reports = array_values(array_filter(
        $degraded,
        static fn (array $entry): bool => $entry['feature'] === 'advanced_reports',
    ));

    expect($reports)->toHaveCount(1)
        ->and($reports[0]['restriction'])->toBe('license_expired')
        ->and($reports[0]['since'])->toStartWith('2025-12-31')
        // Lo que se pierde HOY frente a lo que se perdera cuando exista.
        ->and($reports[0]['implemented'])->toBeTrue();
})->group('RF-PD-04', 'RF-PD-05');

it('enseña las cifras del plan frente a las reales', function (): void {
    // ADR-028: las tres cifras que el fabricante pide en una revision.
    Api::as(licenseAdminToken())->post('/api/v1/license/activate', [
        'signed_key' => LicenseKeys::current()->issue(['max_employees' => 2, 'max_devices' => 1]),
    ]);

    $siteId = WorkforceFixtures::onlySiteId();
    WorkforceFixtures::employee($siteId);
    WorkforceFixtures::employee($siteId);
    WorkforceFixtures::employee($siteId);

    $response = Api::as(licenseAdminToken())->get('/api/v1/license')->assertValidResponse(200);

    expect($response->json('data.limits.0'))->toMatchArray([
        'limit' => 'max_employees',
        'contracted' => 2,
        'actual' => 3,
        'exceeded' => true,
        'excess' => 1,
    ])->and($response->json('meta.needs_notice'))->toBeTrue()
        // Un exceso de plan es aviso, no critico: ocurre con contrato vigente y
        // pagado, y castigar al cliente que crece seria desproporcionado.
        ->and($response->json('data.severity'))->toBe('warning');
})->group('RF-PD-04', 'RF-PD-05');

it('rechaza una clave que no verifica con un 422 que dice que hacer', function (): void {
    $otro = LicenseKeys::mint();

    $response = Api::as(licenseAdminToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->post('/api/v1/license/activate', ['signed_key' => $otro->issue()])
        ->assertValidRequest()
        ->assertValidResponse(422);

    expect($response->json('errors.signed_key.0'))->toContain('Pide una clave nueva')
        // La licencia anterior —ninguna, en este caso— sigue como estaba.
        ->and(DB::table('license')->count())->toBe(0);
})->group('RF-PD-04');

it('distingue los cuatro motivos de rechazo, porque la accion es distinta', function (string $key, string $fragment): void {
    $response = Api::as(licenseAdminToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->post('/api/v1/license/activate', ['signed_key' => $key])
        ->assertValidResponse(422);

    expect($response->json('errors.signed_key.0'))->toContain($fragment);
})->with([
    'clave a medias' => ['KQL1.solo-dos-partes', 'incompleta'],
    'firma de otro emisor' => [fn () => LicenseKeys::mint()->issue(), 'no la emitió el fabricante'],
    'emision defectuosa' => [fn () => LicenseKeys::current()->sign('{"plan":"estandar"}'), 'fallo de emisión'],
])->group('RF-PD-04');

it('avisa de que esta compilacion no lleva clave publica del fabricante', function (): void {
    // No es un problema de la clave del cliente: es del despliegue, y el mensaje
    // manda a revisar eso en vez de a pedir otra clave.
    config()->set('license.public_key', '');

    $response = Api::as(licenseAdminToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->post('/api/v1/license/activate', ['signed_key' => LicenseKeys::current()->issue()])
        ->assertValidResponse(422);

    expect($response->json('errors.signed_key.0'))->toContain('clave pública del fabricante');
})->group('RF-PD-04');

it('rechaza un campo que el endpoint no conoce', function (): void {
    Api::as(licenseAdminToken())
        ->post('/api/v1/license/activate', [
            'signed_key' => LicenseKeys::current()->issue(),
            'customer_name' => 'Lo pongo yo',
        ])
        ->assertValidResponse(422);

    expect(DB::table('license')->count())->toBe(0);
})->group('RF-PD-04');

it('expone el estado de licencia en la sonda de salud', function (): void {
    // §10.5 y paso 9 de la tarea: `doctor` y el paquete de diagnostico tienen
    // que poder informarlo.
    Api::as(licenseAdminToken())->post('/api/v1/license/activate', ['signed_key' => LicenseKeys::current()->issue()]);

    $health = Api::guest()->get('/api/v1/health')->assertValidResponse(200);

    expect($health->json('license'))->toBe('valid')
        // Una palabra y nada mas: la sonda es publica y el resto es informacion
        // comercial del cliente (ADR-020, regla dura 21).
        ->and($health->getContent())->not->toContain('Hotel de Pruebas')
        ->and($health->getContent())->not->toContain('estandar')
        ->and(array_keys((array) $health->json()))->toBe(['status', 'version', 'license']);
})->group('RF-PD-04');

it('la sonda de salud dice «unknown» si no ha podido saberlo sin tocar nada', function (): void {
    // La sonda de vida no consulta la base de datos: si lo hiciera, Docker
    // reiniciaria PHP cuando lo caido es PostgreSQL. Sin copia en cache, la
    // respuesta honesta es «no lo se», que NO es lo mismo que «no hay
    // licencia».
    cache()->forget(LicenseStateProbe::CACHE_KEY);

    expect(Api::guest()->get('/api/v1/health')->assertValidResponse(200)->json('license'))->toBe('unknown');
})->group('RF-PD-04');
