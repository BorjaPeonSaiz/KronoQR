<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `license.branding_not_applied` de `doctor` (PR18, RF-PD-05, RF-PD-08,
 * ADR-023). El nombre de la instalacion no se degrada nunca, asi que no cuenta
 * como marca que se deja de mostrar y el aviso no aconseja borrarlo; y el texto
 * distingue «el plan no la incluye» de «la licencia no esta en vigor».
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    FrozenTime::at('2026-06-15 09:00:00');
});

/**
 * @param  list<string>  $features
 */
function marcaDoctorLicencia(array $features, string $validUntil = '2026-12-31T23:59:59Z'): void
{
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(LicenseKeys::current()->issue([
        'features' => $features,
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => $validUntil,
    ])));

    app()->forgetInstance(FeatureGate::class);
}

/**
 * @param  array<string, string>  $settings
 */
function marcaDoctorGuardar(array $settings): void
{
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => $settings])
        ->assertStatus(200);

    Auth::forgetGuards();
}

function marcaDoctorComprobacion(string $locale = 'es'): DoctorCheck
{
    $checks = array_values(array_filter(
        app(RunDoctorHandler::class)->handle($locale)->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'license.branding_not_applied',
    ));

    expect($checks)->toHaveCount(1);

    return $checks[0];
}

it('no avisa por el nombre sin el plan: el nombre se sigue mostrando', function (): void {
    marcaDoctorLicencia(['advanced_reports']);
    marcaDoctorGuardar(['BRANDING_APP_NAME' => 'Hotel Marina']);

    $check = marcaDoctorComprobacion();

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->details)->toBe(['configured' => false, 'allowed' => false]);
})->group('RF-PD-05', 'RF-PD-08', 'RF-PD-13');

it('con color sin el plan avisa de ampliar el plan, sin aconsejar borrar el nombre', function (string $locale, string $plan, string $nombre): void {
    marcaDoctorLicencia(['advanced_reports']);
    marcaDoctorGuardar(['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_ACCENT_COLOR' => '#0f5c8c']);

    $check = marcaDoctorComprobacion($locale);

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->details)->toBe(['configured' => true, 'allowed' => false, 'restriction' => 'not_in_plan'])
        ->and($check->summary)->toContain($plan)
        ->and($check->summary)->toContain($nombre)
        ->and((string) $check->fix)->not->toContain('borra el nombre')
        ->and((string) $check->fix)->not->toContain('clear the name');
})->with([
    'es' => ['es', 'el plan contratado no incluye', 'El nombre de la instalacion se sigue mostrando'],
    'en' => ['en', 'the contracted plan does not include', 'The installation name is still shown'],
])->group('RF-PD-05', 'RF-PD-08', 'RF-PD-13');

it('con la licencia caducada dice que se renueve, no que el plan no la incluye', function (string $locale, string $vigor, string $plan, string $renovar): void {
    marcaDoctorLicencia(['white_label'], validUntil: '2026-05-31T23:59:59Z');
    marcaDoctorGuardar(['BRANDING_ACCENT_COLOR' => '#0f5c8c']);

    $check = marcaDoctorComprobacion($locale);

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->details)->toBe(['configured' => true, 'allowed' => false, 'restriction' => 'license_expired'])
        ->and($check->summary)->toContain($vigor)
        ->and($check->summary)->not->toContain($plan)
        ->and((string) $check->fix)->toContain($renovar);
})->with([
    'es' => ['es', 'la licencia no esta en vigor', 'plan contratado', 'Renueva o activa la licencia'],
    'en' => ['en', 'the licence is not in force', 'contracted plan', 'Renew or activate the licence'],
])->group('RF-PD-05', 'RF-PD-08', 'RF-PD-13');

it('con el plan que la incluye no avisa', function (): void {
    marcaDoctorLicencia(['white_label']);
    marcaDoctorGuardar(['BRANDING_APP_NAME' => 'Hotel Marina', 'BRANDING_ACCENT_COLOR' => '#0f5c8c']);

    $check = marcaDoctorComprobacion();

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->details)->toBe(['configured' => true, 'allowed' => true]);
})->group('RF-PD-05', 'RF-PD-08', 'RF-PD-13');
