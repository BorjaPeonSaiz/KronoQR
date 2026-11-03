<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ConsoleSignature;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Shared\DeactivatedWhileSigning;
use Tests\Support\Shared\InteractiveConsole;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `credentials:revoke`: retirar una tarjeta por consola (RF-QR-03), firmado
 * por la cuenta de `--responsible` (doc 01 §7.2 «Firmas por consola»).
 *
 * Lo que fijan estas pruebas: la cuenta se nombra por su uuid o su correo y
 * nunca por su id; firma admin o rrhh, como en el panel; con `--force` o sin
 * terminal hace falta `--responsible-confirm`; el asiento `credential.revoked`
 * es de esa cuenta y no de `system`; y cada negativa sale con su codigo sin
 * revocar ni escribir nada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-08-21 06:40:15');
    app()->setLocale('es');
    app()->instance(CardRenderer::class, new FakeCardRenderer);
});

/** Una tarjeta ya impresa, de las que sirven para fichar. Devuelve su uuid. */
function revocacionPorConsolaTarjeta(): string
{
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $uuid = Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => WorkforceFixtures::employee(WorkforceFixtures::site())])
        ->assertStatus(201)
        ->json('uuid');

    expect($uuid)->toBeString();
    /** @var string $uuid */
    Api::as($token)->post('/api/v1/credentials/'.$uuid.'/print')->assertStatus(200);

    return $uuid;
}

/**
 * @param  array<string, string|bool>  $opciones
 * @return array{code: int, output: string}
 */
function revocacionPorConsolaOrden(string $credencial, array $opciones): array
{
    $code = Artisan::call('credentials:revoke', ['credential' => $credencial, '--reason' => 'perdida', ...$opciones]);

    return ['code' => $code, 'output' => Artisan::output()];
}

/** Lo que una negativa no puede dejar: la tarjeta sigue sirviendo y no hay asiento. */
function revocacionPorConsolaSinRastro(string $credencial): void
{
    expect(DB::table('audit_log')->where('action', 'credential.revoked')->count())->toBe(0)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('revoked_at'))->toBeNull();
}

it('revoca la tarjeta con el asiento a nombre de la cuenta de --responsible, no de system', function (UserRole $rol, bool $porUuid): void {
    $credencial = revocacionPorConsolaTarjeta();
    $cuenta = ManagementUsers::withRole($rol);

    $orden = revocacionPorConsolaOrden($credencial, $porUuid ? ConsoleSignature::byUuid($cuenta) : ConsoleSignature::for($cuenta));

    /** @var object{actor_type: string, actor_id: int} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'credential.revoked')->sole();

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$cuenta->name.' <'.$cuenta->email.'> · '.$rol->value.' · '.$cuenta->uuid)
        ->and($asiento->actor_type)->toBe('user')
        ->and($asiento->actor_id)->toBe($cuenta->id)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('revoked_reason'))->toBe('perdida');
})->with([
    'rrhh por correo' => [UserRole::RRHH, false],
    'admin por uuid' => [UserRole::ADMIN, true],
])->group('RF-QR-03', 'RL-04');

it('sale con 2 sin revocar ni escribir si la firma no vale', function (Closure $opciones, string $dice): void {
    $credencial = revocacionPorConsolaTarjeta();

    $orden = revocacionPorConsolaOrden($credencial, ['--no-interaction' => true, ...$opciones()]);

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain($dice);

    revocacionPorConsolaSinRastro($credencial);
})->with([
    'sin --responsible' => [fn (): array => [], 'Falta --responsible'],
    'id numerico' => [fn (): array => ['--responsible' => (string) ManagementUsers::withRole(UserRole::RRHH)->id], 'ya no admite el id interno'],
    'responsable de departamento' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)), '(admin, rrhh)'],
    'auditor' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::AUDITOR)), '(admin, rrhh)'],
    'cuenta dada de baja' => [function (): array {
        $baja = ManagementUsers::withRole(UserRole::RRHH);
        DB::table('users')->where('id', $baja->id)->update(['is_active' => false]);

        return ConsoleSignature::for($baja);
    }, 'dada de baja'],
    'sin terminal y sin confirmar la firma' => [fn (): array => ['--responsible' => ManagementUsers::withRole(UserRole::RRHH)->email], '--responsible-confirm=<correo de la cuenta>'],
    'confirmacion de otra cuenta' => [fn (): array => [
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::RRHH)),
        '--responsible-confirm' => 'otra@kronoqr.test',
    ], 'no coincide'],
])->group('RF-QR-03', 'RL-04');

it('sale con 1 sin escribir si la credencial no existe o ya estaba revocada', function (): void {
    $credencial = revocacionPorConsolaTarjeta();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $inexistente = revocacionPorConsolaOrden('0192f1a4-0000-7000-8000-000000000000', ConsoleSignature::for($rrhh));

    expect($inexistente['code'])->toBe(1, $inexistente['output'])
        ->and(DB::table('audit_log')->where('action', 'credential.revoked')->count())->toBe(0);

    expect(revocacionPorConsolaOrden($credencial, ConsoleSignature::for($rrhh))['code'])->toBe(0);

    expect(revocacionPorConsolaOrden($credencial, ConsoleSignature::for($rrhh))['code'])->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'credential.revoked')->count())->toBe(1);
})->group('RF-QR-03');

it('con --force y terminal no pregunta, pero exige --responsible-confirm', function (): void {
    $credencial = revocacionPorConsolaTarjeta();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $orden = ['credential' => $credencial, '--reason' => 'perdida', '--responsible' => $rrhh->email, '--force' => true];

    $sinConfirmar = InteractiveConsole::run('credentials:revoke', $orden, 'si');

    expect($sinConfirmar['code'])->toBe(2, $sinConfirmar['output'])
        ->and($sinConfirmar['output'])->toContain('--responsible-confirm=<correo de la cuenta>');

    revocacionPorConsolaSinRastro($credencial);

    $confirmada = InteractiveConsole::run('credentials:revoke', [...$orden, '--responsible-confirm' => $rrhh->email], '');

    expect($confirmada['code'])->toBe(0, $confirmada['output'])
        ->and(DB::table('audit_log')->where('action', 'credential.revoked')->value('actor_id'))->toBe($rrhh->id);
})->group('RF-QR-03', 'RL-04');

it('con terminal pregunta por la firma y, si se responde que no, sale con 1 sin revocar', function (): void {
    $credencial = revocacionPorConsolaTarjeta();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $orden = InteractiveConsole::run('credentials:revoke', [
        'credential' => $credencial,
        '--reason' => 'perdida',
        '--responsible' => $rrhh->email,
    ], 'no');

    expect($orden['code'])->toBe(1, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$rrhh->name)
        ->and($orden['output'])->toContain('Cancelado. No se ha escrito nada.');

    revocacionPorConsolaSinRastro($credencial);
})->group('RF-QR-03', 'RL-04');

it('una baja entre la pregunta y la firma no revoca ni deja asiento (salida 2)', function (): void {
    $credencial = revocacionPorConsolaTarjeta();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    DeactivatedWhileSigning::after(0);

    $orden = revocacionPorConsolaOrden($credencial, ConsoleSignature::for($rrhh));

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain('ya no es una cuenta de gestión activa');

    revocacionPorConsolaSinRastro($credencial);
})->group('RF-QR-03', 'RL-04', 'RS-07');

it('por el panel la revocacion sigue firmada por la sesion, sin pasar por la firma de consola', function (): void {
    $credencial = revocacionPorConsolaTarjeta();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    DeactivatedWhileSigning::after(0);

    Api::as(ManagementUsers::tokenFor($rrhh))
        ->post('/api/v1/credentials/'.$credencial.'/revoke', ['reason' => 'perdida'])
        ->assertStatus(200);

    expect(DB::table('audit_log')->where('action', 'credential.revoked')->value('actor_id'))->toBe($rrhh->id);
})->group('RF-QR-03');
