<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Con la licencia caducada, `doctor` y el paquete funcionan** (RF-PD-05,
 * regla dura 15, ADR-019).
 *
 * Es la prueba que la ficha 5.9 exige con esas palabras, y no es una mas: el
 * diagnostico es *justamente lo que se necesita cuando algo va mal*. Un producto
 * que apagara su propio diagnostico al caducar la licencia dejaria al cliente
 * sin la herramienta con la que arreglaria el problema —incluido el de renovar—.
 *
 * Se prueban los tres estados en los que un cliente se puede encontrar: sin
 * licencia (instalacion recien montada), con una caducada (renovacion tardia) y
 * con una ilegible (clave cortada al copiarla de un correo).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

function tokenDeDiagnosticoSinLicencia(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/** Deja la instalacion con una licencia que caduco hace un mes. */
function diagnosticoConLicenciaCaducada(): void
{
    $keys = LicenseKeys::install();

    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-08-01T00:00:00Z',
    ])));

    app()->instance(Clock::class, FixedClock::at('2026-09-01 10:00:00'));
    app()->forgetInstance(FeatureGate::class);
}

/**
 * Deja la instalacion con una clave que no verifica: el caso real de alguien
 * que pega media clave desde un correo.
 */
function diagnosticoConLicenciaIlegible(): void
{
    DB::table('license')->insert([
        'signed_key' => 'esto-no-es-una-clave',
        'license_id' => 'x',
        'customer_name' => 'x',
        'plan' => 'x',
        'max_employees' => 1,
        'max_devices' => 1,
        'features' => '[]',
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2026-01-01T00:00:00Z',
        'activated_at' => '2026-01-01T00:00:00Z',
        'activated_by_user_id' => null,
        'last_verified_at' => null,
    ]);
}

it('genera el paquete sin ninguna licencia activada', function (): void {
    $response = Api::as(tokenDeDiagnosticoSinLicencia())->post('/api/v1/diagnostics/bundle')->assertStatus(200);

    expect($response->json('license.state'))->toBe('absent')
        // El paquete sale completo: las diez secciones, no una version recortada.
        ->and($response->json('manifest.sections'))->toHaveCount(10);
})->group('RF-PD-05', 'RF-PD-09');

it('genera el paquete con la licencia caducada', function (): void {
    diagnosticoConLicenciaCaducada();

    $response = Api::as(tokenDeDiagnosticoSinLicencia())->post('/api/v1/diagnostics/bundle')->assertStatus(200);

    expect($response->json('license.state'))->toBe('expired')
        ->and($response->json('manifest.sections'))->toHaveCount(10)
        ->and($response->json('doctor.checks'))->not->toBeEmpty();
})->group('RF-PD-05', 'RF-PD-09');

it('genera el paquete con datos personales tambien con la licencia caducada', function (): void {
    // Que la licencia no bloquee **nada** del registro legal incluye lo que el
    // cliente decide sacar de su propia instalacion (ADR-019).
    diagnosticoConLicenciaCaducada();

    Api::as(tokenDeDiagnosticoSinLicencia())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true])
        ->assertStatus(200);
})->group('RF-PD-05', 'RL-19');

it('doctor termina con aviso, nunca con fallo, por el estado de la licencia', function (): void {
    // LA COMPROBACION QUE IMPIDE EL PEOR DEFECTO POSIBLE de esta tarea: un
    // `failure` por licencia devolveria `2`, `update.sh` lo traduciria al `6` de
    // su tabla comun y **una actualizacion se abortaria por una licencia
    // vencida**. Ver `LicenseProbe`.
    diagnosticoConLicenciaCaducada();

    $report = app(RunDoctorHandler::class)->handle('es');

    $license = array_values(array_filter(
        $report->checks,
        static fn (DoctorCheck $check): bool => str_starts_with($check->id, 'license.'),
    ));

    expect($license)->not->toBeEmpty();

    foreach ($license as $check) {
        expect($check->status)->not->toBe(DoctorStatus::Failure);
    }
})->group('RF-PD-05', 'RF-PD-13');

it('doctor y el paquete funcionan con una clave ilegible', function (): void {
    // El caso real: alguien pega media clave desde un correo. El producto lo
    // dice y sigue funcionando.
    diagnosticoConLicenciaIlegible();

    $response = Api::as(tokenDeDiagnosticoSinLicencia())->post('/api/v1/diagnostics/bundle')->assertStatus(200);

    expect($response->json('license.state'))->toBe('unverifiable')
        ->and($response->json('manifest.sections'))->toHaveCount(10);
})->group('RF-PD-05', 'RF-PD-09');
