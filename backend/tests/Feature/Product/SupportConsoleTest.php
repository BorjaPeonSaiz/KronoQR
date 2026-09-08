<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `support:grant` y `support:revoke` (Anexo C del doc 01, RF-PD-11, ADR-020).
 *
 * SON EL CAMINO DE LA INCIDENCIA GRAVE: se ejecutan cuando el panel no esta
 * disponible, que es justo cuando mas falta hace conceder —o cortar— un acceso
 * de soporte. Por eso lo que se prueba aqui no es el formato bonito: es que el
 * token se imprima UNA vez con su aviso, que **la base de datos solo guarde su
 * hash**, y que `--all` corte todo sin pedir identificadores.
 *
 * Se usa `Artisan::call()` y no `$this->artisan()` porque hay que afirmar sobre
 * la salida COMPLETA —que una cadena aparece exactamente una vez, por ejemplo— y
 * no linea a linea.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function runSupportCommand(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('support:grant concede, imprime el token UNA vez y avisa de que no se repite', function (): void {
    // Un solo administrador: la instalacion recien montada. Sin `--as`, se
    // atribuye a esa cuenta y el comando lo dice.
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $result = runSupportCommand('support:grant', [
        '--reason' => 'Incidencia #123 desde consola',
        '--hours' => 8,
    ]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Acceso de soporte concedido.')
        ->and($result['output'])->toContain('ESTE TOKEN SE MUESTRA UNA SOLA VEZ')
        ->and($result['output'])->toContain($admin->name)
        // Que hacer despues, para quien no conoce el sistema.
        ->and($result['output'])->toContain('php artisan support:revoke');

    $row = DB::table('support_grants')->first();

    expect($row?->reason)->toBe('Incidencia #123 desde consola')
        ->and($row?->scope)->toBe('diagnostics')
        ->and($row?->granted_by_user_id)->toBe($admin->id);

    // EL TOKEN APARECE EXACTAMENTE UNA VEZ EN LA SALIDA y en ningun otro sitio.
    $token = tokenDeLaSalida($result['output']);

    expect(substr_count($result['output'], $token))->toBe(1)
        ->and($row?->token_hash)->not->toBe($token)
        ->and(DB::table('support_grants')->where('token_hash', $token)->count())->toBe(0);
})->group('RF-PD-11');

it('support:grant no escribe el token en la base de datos ni en el trail', function (): void {
    ManagementUsers::withRole(UserRole::ADMIN);

    $token = tokenDeLaSalida(runSupportCommand('support:grant', [
        '--reason' => 'Incidencia #123 desde consola',
    ])['output']);

    // Ni en su fila, ni en el asiento de auditoria —que se conserva cuatro años y
    // se exporta—.
    /** @var string $asiento */
    $asiento = DB::table('audit_log')->where('action', 'support_grant.granted')->value('payload');

    expect(DB::table('support_grants')->where('token_hash', $token)->count())->toBe(0)
        ->and($asiento)->not->toContain($token);
})->group('RF-PD-11', 'RS-08');

it('support:grant exige decir a nombre de quien se concede si hay varias cuentas', function (): void {
    // Atribuir el acto a una cuenta elegida al azar entre varias seria poner el
    // nombre de otra persona en la firma del encargo del art. 28 RGPD (RL-18).
    ManagementUsers::withRole(UserRole::ADMIN);
    ManagementUsers::withRole(UserRole::RRHH);

    $result = runSupportCommand('support:grant', ['--reason' => 'Incidencia #123']);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('--as=')
        ->and(DB::table('support_grants')->count())->toBe(0);
})->group('RF-PD-11', 'RL-18');

it('support:grant acepta --as con el correo de la cuenta', function (): void {
    ManagementUsers::withRole(UserRole::RRHH);
    $admin = ManagementUsers::withRole(UserRole::ADMIN, 'responsable@hotel.example');

    $result = runSupportCommand('support:grant', [
        '--reason' => 'Incidencia #123',
        '--as' => 'responsable@hotel.example',
    ]);

    expect($result['code'])->toBe(0)
        ->and(DB::table('support_grants')->value('granted_by_user_id'))->toBe($admin->id);
})->group('RF-PD-11');

it('support:grant rechaza un motivo vacio, una duracion imposible y un alcance inventado', function (array $options): void {
    ManagementUsers::withRole(UserRole::ADMIN);

    expect(runSupportCommand('support:grant', $options)['code'])->toBe(1)
        ->and(DB::table('support_grants')->count())->toBe(0);
})->with([
    'sin motivo' => [[]],
    'motivo demasiado corto' => [['--reason' => 'ab']],
    'mas horas que el tope' => [['--reason' => 'Incidencia #1', '--hours' => 999]],
    'alcance inventado' => [['--reason' => 'Incidencia #1', '--scope' => 'todo']],
])->group('RF-PD-11');

it('support:revoke retira una concesion y conserva su fila', function (): void {
    $issued = SupportGrants::issue();

    $result = runSupportCommand('support:revoke', ['uuid' => $issued->grant->uuid]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Acceso revocado')
        ->and(DB::table('support_grants')->count())->toBe(1)
        ->and(DB::table('support_grants')->value('revoked_at'))->not->toBeNull()
        // Sin sesion detras: el asiento dice `system`, que es la verdad y
        // distingue esto de una revocacion desde el panel.
        ->and(DB::table('audit_log')->where('action', 'support_grant.revoked')->value('actor_type'))
        ->toBe('system');
})->group('RF-PD-11', 'RL-04');

it('support:revoke es idempotente y no vuelve a auditar', function (): void {
    $issued = SupportGrants::issue();

    runSupportCommand('support:revoke', ['uuid' => $issued->grant->uuid]);
    $segunda = runSupportCommand('support:revoke', ['uuid' => $issued->grant->uuid]);

    expect($segunda['code'])->toBe(0)
        ->and($segunda['output'])->toContain('ya estaba revocada')
        ->and(DB::table('audit_log')->where('action', 'support_grant.revoked')->count())->toBe(1);
})->group('RF-PD-11');

it('support:revoke --all es el boton de panico', function (): void {
    // La pregunta de las tres de la mañana no es «¿cual revoco?», es «corta todo
    // acceso del fabricante ahora mismo».
    SupportGrants::issue(reason: 'Incidencia #1');
    SupportGrants::issue(reason: 'Incidencia #2');
    SupportGrants::issue(reason: 'Incidencia #3');

    $result = runSupportCommand('support:revoke', ['--all' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('3 acceso(s) de soporte revocado(s)')
        ->and(DB::table('support_grants')->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('support_grants')->count())->toBe(3);
})->group('RF-PD-11');

it('support:revoke --all sin nada abierto sale con 0', function (): void {
    // Quien lo ejecuta quiere que no haya accesos abiertos, y ese es el
    // resultado. Devolver error por conseguir lo que se pedia complicaria
    // cualquier script que lo llamara.
    $result = runSupportCommand('support:revoke', ['--all' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Nada que revocar');
})->group('RF-PD-11');

it('support:revoke sin argumentos dice que hacer y no revoca nada', function (): void {
    SupportGrants::issue();

    $result = runSupportCommand('support:revoke');

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('--all')
        ->and(DB::table('support_grants')->whereNull('revoked_at')->count())->toBe(1);
})->group('RF-PD-11');

it('support:revoke con un identificador que no existe sale con 1', function (): void {
    $result = runSupportCommand('support:revoke', ['uuid' => '0199f6a2-0000-0000-0000-000000000000']);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('No hay ninguna concesion');
})->group('RF-PD-11');

it('con la licencia caducada los dos comandos funcionan igual', function (): void {
    // Regla dura 15: es cuando mas falta hacen.
    ManagementUsers::withRole(UserRole::ADMIN);
    Artisan::call('license:activate', ['key' => LicenseKeys::current()->issue([
        'valid_from' => '2025-01-01T00:00:00Z',
        'valid_until' => '2025-12-31T23:59:59Z',
    ])]);

    expect(runSupportCommand('support:grant', ['--reason' => 'Incidencia con licencia caducada'])['code'])->toBe(0)
        ->and(runSupportCommand('support:revoke', ['--all' => true])['code'])->toBe(0);
})->group('RF-PD-11', 'RF-PD-05');

/**
 * El token que imprimio `support:grant`.
 *
 * Se busca por la forma de un token de Sanctum (`<id>|<secreto>`) y no por
 * posicion, para que un cambio de redaccion de la salida no rompa la prueba que
 * comprueba que el token no se guarda.
 */
function tokenDeLaSalida(string $output): string
{
    preg_match('/^\d+\|[A-Za-z0-9]+$/m', $output, $matches);

    return $matches[0] ?? '';
}
