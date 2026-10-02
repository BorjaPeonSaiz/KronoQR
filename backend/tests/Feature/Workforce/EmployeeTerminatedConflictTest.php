<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **CADA 409 DE LA FICHA DICE CUAL ES** (RN-14, ADR-046; revision del bloque 17
 * de la 2.2.0).
 *
 * «La persona ya esta de baja» sale con `urn:kronoqr:problem:employee-terminated`
 * y el correo duplicado con el generico `urn:kronoqr:problem:conflict`: el panel
 * decide por el `type`, y con el mismo para los dos trataba el correo duplicado
 * como una baja y perdia lo escrito.
 *
 * Y a una persona de baja no se le restablece ni se le entrega el PIN: antes
 * respondia `200` y dejaba el asiento `pin.reset` o `pin.delivered`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int}
 */
function contextoDelConflictoDeBaja(): array
{
    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => WorkforceFixtures::site(),
    ];
}

function asientosDeLaPersonaDeBaja(string $action, string $uuid): int
{
    return DB::table('audit_log')->where('action', $action)->where('payload->employee_uuid', $uuid)->count();
}

it('responde employee-terminated a la modificacion de una persona de baja', function (): void {
    $contexto = contextoDelConflictoDeBaja();
    $baja = WorkforceFixtures::employee($contexto['site'], status: 'terminated');

    Api::as($contexto['token'])
        ->patch('/api/v1/employees/'.$baja, ['last_name' => 'Otro'])
        ->assertValidRequest()
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:employee-terminated')
        ->assertJsonPath('title', 'La persona ya esta dada de baja');

    expect(DB::table('employees')->where('uuid', $baja)->value('last_name'))->toBe('De Prueba');
})->group('RN-14', 'RF-GP-01');

it('responde el conflicto generico al correo que ya es de otra persona', function (): void {
    $contexto = contextoDelConflictoDeBaja();
    $otra = WorkforceFixtures::employee($contexto['site']);
    DB::table('employees')->where('uuid', $otra)->update(['email' => 'ocupado@hotel.example']);
    $persona = WorkforceFixtures::employee($contexto['site']);

    Api::as($contexto['token'])
        ->patch('/api/v1/employees/'.$persona, ['email' => 'ocupado@hotel.example'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-GP-01');

it('no restablece el PIN de una persona de baja ni deja asiento', function (): void {
    $contexto = contextoDelConflictoDeBaja();
    $baja = WorkforceFixtures::employee($contexto['site'], status: 'terminated');
    EmployeePins::issue($baja, '374195');
    $hash = DB::table('employees')->where('uuid', $baja)->value('pin_hash');

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$baja.'/pin/reset')
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:employee-terminated');

    expect(DB::table('employees')->where('uuid', $baja)->value('pin_hash'))->toBe($hash)
        ->and(asientosDeLaPersonaDeBaja('pin.reset', $baja))->toBe(0);
})->group('RN-14', 'RF-ID-09');

it('no registra la entrega del PIN de una persona de baja ni deja asiento', function (): void {
    $contexto = contextoDelConflictoDeBaja();
    $baja = WorkforceFixtures::employee($contexto['site'], status: 'terminated');
    EmployeePins::issue($baja, '374195');

    Api::as($contexto['token'])
        ->post('/api/v1/employees/'.$baja.'/pin/deliver')
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:employee-terminated');

    expect(DB::table('employees')->where('uuid', $baja)->value('pin_delivered_at'))->toBeNull()
        ->and(asientosDeLaPersonaDeBaja('pin.delivered', $baja))->toBe(0);
})->group('RN-14', 'RF-ID-09');

it('sigue respondiendo 404 al PIN de quien no existe', function (string $accion): void {
    $contexto = contextoDelConflictoDeBaja();

    Api::as($contexto['token'])
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/pin/'.$accion)
        ->assertValidResponse(404);
})->with(['reset', 'deliver'])->group('RF-ID-09');

it('responde 409 y no 500 a un documento de identidad que ya es de otra persona', function (): void {
    $contexto = contextoDelConflictoDeBaja();
    $alta = ['first_name' => 'Lucia', 'last_name' => 'Ferrer', 'hired_at' => '2026-08-14', 'national_id' => '12345678Z'];

    Api::as($contexto['token'])->post('/api/v1/employees', $alta)->assertValidResponse(201);

    Api::as($contexto['token'])
        ->post('/api/v1/employees', [...$alta, 'first_name' => 'Otra'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    expect(DB::table('employees')->count())->toBe(1);
})->group('RF-GP-01', 'RL-08');
