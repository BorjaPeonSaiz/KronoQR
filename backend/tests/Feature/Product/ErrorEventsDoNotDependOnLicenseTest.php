<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Con la licencia caducada o ausente, el historico de errores funciona
 * entero** (RF-PD-15, RF-PD-05, regla dura 15, ADR-019).
 *
 * Es la hermana de `DiagnosticsDoNotDependOnLicenseTest` y con mas motivo que
 * ella: esta es **la pantalla que dice por que algo no funciona**. Cerrarla al
 * caducar seria apagar la luz justo al entrar en la habitacion a oscuras — y el
 * problema que hay que diagnosticar puede ser precisamente que la renovacion no
 * se activa.
 *
 * Lo que se comprueba son los cuatro caminos: leer, resolver, reportar desde un
 * cliente y el comando de consola.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/** Deja la instalacion con una licencia que caduco hace un mes. */
function historicoConLicenciaCaducada(): void
{
    $keys = LicenseKeys::install();

    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-08-01T00:00:00Z',
    ])));

    app()->instance(Clock::class, FixedClock::at('2026-09-01 10:00:00'));
    app()->forgetInstance(FeatureGate::class);
}

/** Un grupo abierto sobre el que operar. */
function grupoSinLicencia(): int
{
    $ahora = now()->toDateTimeString('microsecond');

    return (int) DB::table('error_events')->insertGetId([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'critical',
        'source' => 'scheduler',
        'module' => 'compliance',
        'message' => 'la reconciliacion nocturna fallo',
        'exception_class' => 'RuntimeException',
        'file' => 'app/Foo.php',
        'line' => 10,
        'context' => '{}',
        'app_version' => '2.2.0',
        'occurrences' => 2,
        'first_seen_at' => $ahora,
        'last_seen_at' => $ahora,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);
}

it('se lee y se resuelve sin ninguna licencia activada', function (): void {
    DB::table('license')->delete();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $id = grupoSinLicencia();

    Api::as($token)->get('/api/v1/diagnostics/errors')->assertOk()->assertJsonCount(1, 'data');
    Api::as($token)->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertOk();
})->group('RF-PD-15', 'RF-PD-05');

it('se lee y se resuelve con la licencia caducada', function (): void {
    historicoConLicenciaCaducada();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $id = grupoSinLicencia();

    Api::as($token)->get('/api/v1/diagnostics/errors')->assertOk()->assertJsonCount(1, 'data');
    Api::as($token)->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertOk();
})->group('RF-PD-15', 'RF-PD-05');

it('el panel sigue pudiendo reportar sus errores con la licencia caducada', function (): void {
    // El buffer del cliente tiene techo: dejar de aceptar errores significaria
    // perderlos justo cuando la instalacion tiene un problema.
    historicoConLicenciaCaducada();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', [
            'errors' => [[
                'code' => 'web.vue_error',
                'occurred_at' => now()->toIso8601ZuluString('microsecond'),
                'app_version' => '2.2.0',
                'context' => ['component' => 'LicenseView'],
            ]],
        ])
        ->assertStatus(202);
})->group('RF-PD-15', 'RF-PD-05');

it('el comando de consola funciona sin licencia y conserva sus codigos de salida', function (): void {
    DB::table('license')->delete();
    grupoSinLicencia();

    [$codigo, $salida] = Commands::run('product:errors --since=24h --lang=es');

    expect($codigo)->toBe(2)
        ->and($salida)->toContain('CRITICO')
        // Ni una palabra sobre la licencia: quien ejecuta esto esta mirando un
        // problema tecnico.
        ->and($salida)->not->toContain('licencia');
})->group('RF-PD-15', 'RF-PD-05');
