<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Con la licencia caducada, ausente o ilegible, el cliente se lleva sus
 * datos** (RF-PD-05, RL-20, regla dura 15, ADR-019).
 *
 * Es la prueba que la ficha 5.10 exige con esas palabras, y de todas las de la
 * Fase 5 es la que mas directamente protege al cliente. RL-20 dice por que
 * existe la exportacion integra: *«para seguir cumpliendo su obligacion de
 * conservacion aunque la relacion comercial termine»*. Una exportacion cerrada
 * por licencia caducada seria exactamente lo contrario:
 *
 *   - dejaria al cliente **sin acceso a datos que la ley le obliga a conservar
 *     cuatro años** (RL-02), por una accion del fabricante;
 *   - y lo haria justo en el momento en que mas falta le hace, que es cuando ha
 *     decidido dejar de pagar.
 *
 * Se prueban los tres estados reales: sin licencia (instalacion recien montada o
 * relacion terminada), con una caducada (renovacion tardia) y con una ilegible
 * (clave cortada al copiarla de un correo). Y por las dos vias: el panel y la
 * consola, porque quien se queda sin producto puede quedarse tambien sin poder
 * entrar al panel.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

function tokenDeExportacionSinLicencia(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/** Deja la instalacion con una licencia que caduco hace un mes. */
function exportacionConLicenciaCaducada(): void
{
    $keys = LicenseKeys::install();

    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand($keys->issue([
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-08-01T00:00:00Z',
    ])));

    app()->instance(Clock::class, FixedClock::at('2026-09-01 10:00:00'));
    app()->forgetInstance(FeatureGate::class);
}

/** El caso real de alguien que pega media clave desde un correo. */
function exportacionConLicenciaIlegible(): void
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

it('las tres rutas funcionan sin ninguna licencia activada', function (): void {
    Queue::fake();

    $token = tokenDeExportacionSinLicencia();

    Api::as($token)->get('/api/v1/data-export')->assertStatus(200);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(202);

    $export = DataExports::completed();

    Api::as($token)->get('/api/v1/data-export/'.$export->uuid.'/download')->assertStatus(200);
})->group('RF-PD-05', 'RF-PD-14', 'RL-20');

it('las tres rutas funcionan con la licencia caducada', function (): void {
    // Ni `402` de funcionalidad no licenciada ni `403`: exactamente lo mismo que
    // con la licencia al dia. Es la situacion para la que existe RL-20.
    Queue::fake();

    exportacionConLicenciaCaducada();

    $token = tokenDeExportacionSinLicencia();

    Api::as($token)->get('/api/v1/data-export')->assertStatus(200);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(202);

    $export = DataExports::completed();

    Api::as($token)->get('/api/v1/data-export/'.$export->uuid.'/download')->assertStatus(200);
})->group('RF-PD-05', 'RF-PD-14', 'RL-20');

it('las tres rutas funcionan con una clave ilegible', function (): void {
    Queue::fake();

    exportacionConLicenciaIlegible();

    $token = tokenDeExportacionSinLicencia();

    Api::as($token)->get('/api/v1/data-export')->assertStatus(200);
    Api::as($token)->post('/api/v1/data-export')->assertStatus(202);

    $export = DataExports::completed();

    Api::as($token)->get('/api/v1/data-export/'.$export->uuid.'/download')->assertStatus(200);
})->group('RF-PD-05', 'RF-PD-14', 'RL-20');

it('el comando genera la exportacion completa con la licencia caducada', function (): void {
    // La otra via, y la que mas importa cuando la relacion comercial termina:
    // quien se queda sin producto puede quedarse tambien sin poder entrar al
    // panel. El fichero sale entero, no una version recortada.
    exportacionConLicenciaCaducada();

    [$codigo, $salida] = Commands::run('product:export-all');

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('Exportacion integra generada');

    $fila = DB::table('data_exports')->orderByDesc('id')->first();

    expect($fila?->status)->toBe('completed')
        ->and($fila?->requested_via)->toBe('console');

    /** @var array<string, int> $recuentos */
    $recuentos = json_decode((string) $fila?->row_counts, true, 512, JSON_THROW_ON_ERROR);

    // Los dieciocho conjuntos del catalogo, no un subconjunto degradado.
    expect($recuentos)->toHaveCount(18)
        ->and($recuentos)->toHaveKey('employees')
        ->and($recuentos)->toHaveKey('audit_log')
        ->and($recuentos)->toHaveKey('shift_entries');
})->group('RF-PD-05', 'RF-PD-14', 'RL-20');
