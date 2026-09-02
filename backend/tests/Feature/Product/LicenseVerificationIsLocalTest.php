<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Http;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA VERIFICACION ES LOCAL Y NO HACE NINGUNA LLAMADA SALIENTE** (RF-PD-04,
 * ADR-018).
 *
 * ## Por que esto es una prueba y no un comentario
 *
 * Porque una activacion en linea convertiria la conectividad del fabricante en
 * **punto unico de fallo del registro horario de todos sus clientes**. El §11.6
 * declara la salida a internet opcional y el §6.7 exige que el sistema funcione
 * integramente sin ella: una licencia que necesita conectar convierte ese
 * «opcional» en mentira, y el dia que el servicio de activacion cayera, decenas
 * de hoteles se quedarian sin registrar jornada por un problema comercial ajeno
 * a ellos.
 *
 * ## Como se comprueba
 *
 * `Http::preventStrayRequests()` hace **fallar la prueba** si alguien invoca el
 * cliente HTTP sin una respuesta simulada, y `Http::fake([])` no registra
 * ninguna. Despues se afirma que el historial de peticiones esta vacio, que es
 * lo que cierra el hueco de una peticion que se hiciera y devolviera algo.
 *
 * Cubre los cuatro caminos que tocan la licencia: activar, consultar, decidir si
 * una funcionalidad esta habilitada, y los dos comandos de consola.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));

    // Cualquier peticion saliente que no este simulada hace fallar la prueba.
    Http::preventStrayRequests();
    Http::fake([]);
});

it('activar una licencia no hace ninguna peticion saliente', function (): void {
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue()));

    Http::assertNothingSent();
})->group('RF-PD-04');

it('consultar el estado no hace ninguna peticion saliente', function (): void {
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue()));

    app(DescribeLicenseHandler::class)->handle();

    Http::assertNothingSent();
})->group('RF-PD-04');

it('decidir si una funcionalidad esta habilitada no hace ninguna peticion saliente', function (): void {
    // Es el camino que mas veces se recorre: cualquier pantalla del panel.
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue()));

    expect(app(FeatureGate::class)->isEnabled(Feature::AdvancedReports))->toBeTrue();

    Http::assertNothingSent();
})->group('RF-PD-04');

it('el endpoint de licencia no hace ninguna peticion saliente', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/license/activate', ['signed_key' => LicenseKeys::current()->issue()])->assertOk();
    Api::as($token)->get('/api/v1/license')->assertOk();

    Http::assertNothingSent();
})->group('RF-PD-04');

it('los comandos de consola no hacen ninguna peticion saliente', function (): void {
    Artisan::call('license:activate', ['key' => LicenseKeys::current()->issue()]);
    Artisan::call('license:show');

    Http::assertNothingSent();
})->group('RF-PD-04');

it('una licencia caducada tampoco intenta llamar a nadie', function (): void {
    // El caso en el que un producto mal diseñado «revalidaria contra el
    // servidor». Aqui no hay servidor al que revalidar, y ADR-018 lo decide:
    // la licencia no se puede revocar a distancia, y se asume.
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])));

    app(DescribeLicenseHandler::class)->handle();
    app(FeatureGate::class)->isEnabled(Feature::AdvancedReports);

    Http::assertNothingSent();
})->group('RF-PD-04');
