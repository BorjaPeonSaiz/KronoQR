<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Persistence\DatabaseResponsibleAccounts;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ConsoleSignature;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Shared\InteractiveConsole;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `credentials:deliver`: registrar por consola que una tarjeta llego a su
 * titular (RF-QR-06), firmado por la cuenta de `--responsible` (2.3.0 bloque
 * 3, doc 01 §7.2 «Firmas por consola»).
 *
 * Lo que fijan estas pruebas: la cuenta se nombra por su uuid o su correo y
 * nunca por su id; firma admin o rrhh, como en el panel; sin terminal hace
 * falta `--responsible-confirm`; el asiento `credential.delivered` es de esa
 * cuenta y no de `system`; y cada negativa sale con su codigo sin entregar ni
 * escribir nada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-08-21 06:40:15');
    app()->setLocale('es');
    app()->instance(CardRenderer::class, new FakeCardRenderer);
});

/** Una tarjeta ya impresa, lista para entregar. Devuelve su uuid. */
function entregaPorConsolaTarjetaImpresa(): string
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
function entregaPorConsolaOrden(string $credencial, array $opciones): array
{
    $code = Artisan::call('credentials:deliver', ['credential' => $credencial, ...$opciones]);

    return ['code' => $code, 'output' => Artisan::output()];
}

function entregaPorConsolaAsientos(): int
{
    return DB::table('audit_log')->where('action', 'credential.delivered')->count();
}

it('registra la entrega firmada por la cuenta de --responsible, no por system', function (UserRole $rol, bool $porUuid): void {
    $credencial = entregaPorConsolaTarjetaImpresa();
    $cuenta = ManagementUsers::withRole($rol);

    $orden = entregaPorConsolaOrden($credencial, [
        ...ConsoleSignature::for($cuenta),
        ...($porUuid ? ['--responsible' => $cuenta->uuid] : []),
    ]);

    /** @var object{actor_type: string, actor_id: int, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'credential.delivered')->sole();
    /** @var array{delivered_by_user_id: int} $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$cuenta->name.' <'.$cuenta->email.'> · '.$rol->value.' · '.$cuenta->uuid)
        ->and($asiento->actor_type)->toBe('user')
        ->and($asiento->actor_id)->toBe($cuenta->id)
        ->and($payload['delivered_by_user_id'])->toBe($cuenta->id)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('delivered_by_user_id'))->toBe($cuenta->id);
})->with([
    'rrhh por correo' => [UserRole::RRHH, false],
    'admin por uuid' => [UserRole::ADMIN, true],
])->group('RF-QR-06', 'RL-04');

it('sale con 2 sin entregar ni escribir si la firma no vale', function (Closure $opciones, string $dice): void {
    $credencial = entregaPorConsolaTarjetaImpresa();

    $orden = entregaPorConsolaOrden($credencial, ['--no-interaction' => true, ...$opciones()]);

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain($dice)
        ->and(entregaPorConsolaAsientos())->toBe(0)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('delivered_at'))->toBeNull();
})->with([
    'sin --responsible' => [fn (): array => [], 'Falta --responsible'],
    'id numerico' => [fn (): array => ['--responsible' => (string) ManagementUsers::withRole(UserRole::RRHH)->id], 'ya no admite el id interno'],
    'ni uuid ni correo' => [fn (): array => ['--responsible' => 'rrhh'], 'no es ninguno de los dos'],
    'correo que no existe' => [fn (): array => ['--responsible' => 'nadie@kronoqr.test'], 'No hay ninguna cuenta'],
    'responsable de departamento' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)), 'admin, rrhh'],
    'auditor' => [fn (): array => ConsoleSignature::for(ManagementUsers::withRole(UserRole::AUDITOR)), 'admin, rrhh'],
    'cuenta dada de baja' => [function (): array {
        $baja = ManagementUsers::withRole(UserRole::RRHH);
        DB::table('users')->where('id', $baja->id)->update(['is_active' => false]);

        return ConsoleSignature::for($baja);
    }, 'dada de baja'],
    'sin confirmar la firma' => [fn (): array => ['--responsible' => ManagementUsers::withRole(UserRole::RRHH)->email], '--responsible-confirm=<correo de la cuenta>'],
    'confirmacion de otra cuenta' => [fn (): array => [
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::RRHH)),
        '--responsible-confirm' => 'otra@kronoqr.test',
    ], 'no coincide'],
    '--by de la 2.2.1' => [fn (): array => ['--by' => ManagementUsers::withRole(UserRole::RRHH)->email], '--by se retiro en la 2.3.0'],
])->group('RF-QR-06', 'RL-04', 'RS-05');

it('sale con 1 sin escribir si la credencial no existe o ya se entrego', function (): void {
    $credencial = entregaPorConsolaTarjetaImpresa();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $inexistente = entregaPorConsolaOrden('0192f1a4-0000-7000-8000-000000000000', ConsoleSignature::for($rrhh));

    expect($inexistente['code'])->toBe(1, $inexistente['output'])
        ->and(entregaPorConsolaAsientos())->toBe(0);

    expect(entregaPorConsolaOrden($credencial, ConsoleSignature::for($rrhh))['code'])->toBe(0);

    // No es idempotente: una segunda entrega cambiaria responsable y momento.
    expect(entregaPorConsolaOrden($credencial, ConsoleSignature::for($rrhh))['code'])->toBe(1)
        ->and(entregaPorConsolaAsientos())->toBe(1);
})->group('RF-QR-06');

it('con terminal pregunta por la firma y, si se responde que no, sale con 1 sin entregar', function (): void {
    $credencial = entregaPorConsolaTarjetaImpresa();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $orden = InteractiveConsole::run('credentials:deliver', ['credential' => $credencial, '--responsible' => $rrhh->email], 'no');

    expect($orden['code'])->toBe(1, $orden['output'])
        ->and($orden['output'])->toContain('Firma: '.$rrhh->name)
        ->and($orden['output'])->toContain('Cancelado. No se ha escrito nada.')
        ->and(entregaPorConsolaAsientos())->toBe(0)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('delivered_at'))->toBeNull();
})->group('RF-QR-06', 'RL-04');

/**
 * Cuentas que la consola ve activas (`find`, de verdad) y que el caso de uso
 * ya no da por buenas con la cadena tomada: la baja entre la pregunta y la
 * firma.
 */
function entregaPorConsolaBajaAlFirmar(): void
{
    app()->instance(ResponsibleAccounts::class, new class implements ResponsibleAccounts
    {
        public function find(ResponsibleReference $reference): ?ResponsibleAccount
        {
            return new DatabaseResponsibleAccounts(DB::connection())->find($reference);
        }

        public function isActiveManagementAccount(int $userId, array $roles = []): bool
        {
            return false;
        }
    });
}

it('una baja entre la pregunta y la firma no entrega ni deja asiento (salida 2)', function (): void {
    $credencial = entregaPorConsolaTarjetaImpresa();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    entregaPorConsolaBajaAlFirmar();

    $orden = entregaPorConsolaOrden($credencial, ConsoleSignature::for($rrhh));

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain('ya no es una cuenta de gestión activa')
        ->and(entregaPorConsolaAsientos())->toBe(0)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('delivered_at'))->toBeNull();
})->group('RF-QR-06', 'RL-04', 'RS-07');

it('por el panel, una cuenta que ya no puede firmar al entregar recibe 403 sin entrega', function (): void {
    $credencial = entregaPorConsolaTarjetaImpresa();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    entregaPorConsolaBajaAlFirmar();

    Api::as($token)->post('/api/v1/credentials/'.$credencial.'/deliver')
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(entregaPorConsolaAsientos())->toBe(0)
        ->and(DB::table('credentials')->where('uuid', $credencial)->value('delivered_at'))->toBeNull();
})->group('RF-QR-06', 'RL-04', 'RS-05');
