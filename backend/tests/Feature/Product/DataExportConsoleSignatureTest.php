<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ConsoleSignature;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Shared\DeactivatedWhileSigning;
use Tests\Support\Shared\InteractiveConsole;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `product:export-all` firmada por la cuenta de `--responsible` (doc 01 §7.2,
 * «Firmas por consola»; RF-PD-14, RL-20).
 *
 * Lo que fijan estas pruebas: la cuenta se nombra por su uuid o su correo y
 * nunca por su id; firma solo `admin`, como en el panel; con `--force` o sin
 * terminal hace falta `--responsible-confirm`; la fila y los asientos
 * `data_export.*` son de esa cuenta y no de `system`; y cada negativa sale con
 * su codigo sin pedir nada: ni fila ni asiento. `--purge` no lleva firma.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-09-08 10:00:00');
    app()->setLocale('es');
    WorkforceFixtures::site();
    LicenseKeys::install();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

/**
 * @param  array<string, string|bool>  $opciones
 * @return array{code: int, output: string}
 */
function exportacionFirmadaOrden(array $opciones): array
{
    $code = Artisan::call('product:export-all', $opciones);

    return ['code' => $code, 'output' => Artisan::output()];
}

/** Lo que una negativa no puede dejar: ni fila ni asiento. */
function exportacionFirmadaSinRastro(): void
{
    expect(DB::table('data_exports')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'data_export.%')->count())->toBe(0);
}

it('genera la exportacion a nombre de la cuenta de --responsible, no de system', function (bool $porUuid): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $orden = exportacionFirmadaOrden($porUuid ? ConsoleSignature::byUuid($admin) : ConsoleSignature::for($admin));

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$admin->name.' <'.$admin->email.'> · admin · '.$admin->uuid)
        ->and(DB::table('data_exports')->value('requested_by_user_id'))->toBe($admin->id);

    $asientos = DB::table('audit_log')->where('action', 'like', 'data_export.%')->get(['action', 'actor_type', 'actor_id']);

    expect($asientos->pluck('action')->sort()->values()->all())->toBe(['data_export.generated', 'data_export.requested']);

    foreach ($asientos as $asiento) {
        expect($asiento->actor_type)->toBe('user')
            ->and($asiento->actor_id)->toBe($admin->id);
    }
})->with([
    'por correo' => [false],
    'por uuid' => [true],
])->group('RF-PD-14', 'RL-20', 'RL-04', 'RS-05');

it('sale con 2 sin pedir nada si la firma no vale', function (Closure $opciones, string $dice): void {
    $orden = exportacionFirmadaOrden(['--no-interaction' => true, ...$opciones()]);

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain($dice);

    exportacionFirmadaSinRastro();
})->with([
    'sin --responsible' => [fn (): array => [], 'Falta --responsible'],
    'id numerico' => [fn (): array => ['--responsible' => (string) ManagementUsers::withRole(UserRole::ADMIN)->id], 'ya no admite el id interno'],
    'rrhh' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::RRHH)), '(admin)'],
    'auditor' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::AUDITOR)), '(admin)'],
    'responsable de departamento' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)), '(admin)'],
    'cuenta dada de baja' => [function (): array {
        $baja = ManagementUsers::withRole(UserRole::ADMIN);
        DB::table('users')->where('id', $baja->id)->update(['is_active' => false]);

        return ConsoleSignature::for($baja);
    }, 'dada de baja'],
    'sin terminal y sin confirmar la firma' => [fn (): array => ['--responsible' => ManagementUsers::withRole(UserRole::ADMIN)->email], '--responsible-confirm=<correo de la cuenta>'],
    'confirmacion de otra cuenta' => [fn (): array => [
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::ADMIN)),
        '--responsible-confirm' => 'otra@kronoqr.test',
    ], 'no coincide'],
])->group('RF-PD-14', 'RL-04', 'RS-05');

it('con --force y terminal no pregunta, pero exige --responsible-confirm', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $sinConfirmar = InteractiveConsole::run('product:export-all', ['--responsible' => $admin->email, '--force' => true], 'si');

    expect($sinConfirmar['code'])->toBe(2, $sinConfirmar['output'])
        ->and($sinConfirmar['output'])->toContain('--responsible-confirm=<correo de la cuenta>');

    exportacionFirmadaSinRastro();

    $confirmada = InteractiveConsole::run('product:export-all', [
        '--responsible' => $admin->email,
        '--force' => true,
        '--responsible-confirm' => $admin->email,
    ], '');

    expect($confirmada['code'])->toBe(0, $confirmada['output'])
        ->and(DB::table('data_exports')->value('requested_by_user_id'))->toBe($admin->id);
})->group('RF-PD-14', 'RL-04');

it('con terminal pregunta por la firma y, si se responde que no, sale con 1 sin pedir nada', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $orden = InteractiveConsole::run('product:export-all', ['--responsible' => $admin->email], 'no');

    expect($orden['code'])->toBe(1, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$admin->name)
        ->and($orden['output'])->toContain('Cancelado. No se ha escrito nada.');

    exportacionFirmadaSinRastro();
})->group('RF-PD-14', 'RL-04');

it('una baja entre la pregunta y la firma no pide nada (salida 2)', function (int $comprobacionesValidas): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    DeactivatedWhileSigning::after($comprobacionesValidas);

    $orden = exportacionFirmadaOrden(ConsoleSignature::for($admin));

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain('ya no es una cuenta de gestión activa');

    exportacionFirmadaSinRastro();
})->with([
    'antes de empezar' => [0],
    'con la cadena tomada' => [1],
])->group('RF-PD-14', 'RL-04', 'RS-07');

it('--purge no pide firma: es la pasada horaria del planificador', function (): void {
    $orden = exportacionFirmadaOrden(['--purge' => true, '--no-interaction' => true]);

    expect($orden['code'])->toBe(0, $orden['output']);
})->group('RF-PD-14');
