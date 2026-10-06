<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La longitud del PIN como ajuste auditado (RF-ID-09, ADR-050 §1), de punta a
 * punta: se cambia por `PATCH /api/v1/settings`, deja su asiento con impacto
 * `access_control`, `pin/reset` emite con ella y escribe `pin_length`, los PIN
 * emitidos antes siguen valiendo y `pin_length` no sale por la API.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function ponerLongitudDelPin(string $longitud): void
{
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => ['IDENTITY_PIN_LENGTH' => $longitud]])
        ->assertValidResponse(200);
}

it('deja asiento del cambio de longitud con impacto access_control', function (): void {
    ponerLongitudDelPin('8');

    $fila = DB::table('audit_log')->where('action', 'calculation_setting.changed')->sole();
    /** @var array<string, mixed> $payload */
    $payload = json_decode(\is_string($fila->payload) ? $fila->payload : '{}', true, 512, JSON_THROW_ON_ERROR);

    expect($payload['key'])->toBe('IDENTITY_PIN_LENGTH')
        ->and($payload['impact'])->toBe('access_control')
        ->and($payload['affects_worked_hours'])->toBeFalse()
        ->and($payload['previous_value'])->toBe('6')
        ->and($payload['new_value'])->toBe('8');
})->group('RF-ID-09', 'RF-PD-01', 'RL-04');

it('rechaza una longitud de siete', function (string $longitud): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', ['settings' => ['IDENTITY_PIN_LENGTH' => $longitud]])
        ->assertValidResponse(422);
})->with(['7', '4', '10', 'ocho'])->group('RF-ID-09', 'RF-PD-01');

it('restablece con ocho cifras cuando el ajuste esta en ocho y lo anota en pin_length', function (): void {
    ponerLongitudDelPin('8');

    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    EmployeePins::issue($uuid, PortalLogins::PIN);

    expect(DB::table('employees')->where('uuid', $uuid)->value('pin_length'))->toBe(6);

    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $nuevo = Api::as($rrhh)->post('/api/v1/employees/'.$uuid.'/pin/reset')
        ->assertValidResponse(200)
        ->json('pin');

    expect($nuevo)->toBeString()->toMatch('/^[0-9]{8}$/');

    $fila = DB::table('employees')->where('uuid', $uuid)->first(['pin_hash', 'pin_length']);

    expect($fila?->pin_length)->toBe(8)
        ->and(Hash::check(\is_string($nuevo) ? $nuevo : '', \is_string($fila?->pin_hash) ? $fila->pin_hash : ''))->toBeTrue();

    // Y con el nuevo se entra al portal.
    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => EmployeePins::codeOf($uuid),
        'pin' => $nuevo,
    ])->assertValidResponse(200);
})->group('RF-ID-09', 'RF-ID-06');

it('restablece con seis cifras de serie', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    EmployeePins::issue($uuid, PortalLogins::PIN);

    $nuevo = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/employees/'.$uuid.'/pin/reset')
        ->assertValidResponse(200)
        ->json('pin');

    expect($nuevo)->toBeString()->toMatch('/^[0-9]{6}$/')
        ->and(DB::table('employees')->where('uuid', $uuid)->value('pin_length'))->toBe(6);
})->group('RF-ID-09');

it('no invalida los PIN de seis emitidos antes de pasar a ocho', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    EmployeePins::issue($uuid, PortalLogins::PIN);

    ponerLongitudDelPin('8');

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => EmployeePins::codeOf($uuid),
        'pin' => PortalLogins::PIN,
    ])->assertValidResponse(200);
})->group('RF-ID-09', 'RF-ID-06');

it('sigue dejando fichar en el quiosco con el PIN de seis emitido antes de pasar a ocho', function (): void {
    // El fichaje de respaldo (RF-AT-11) tampoco mira la longitud: compara el
    // hash. Con el ajuste en 8, quien conserva su PIN de 6 sigue fichando por
    // `/scan/pin` hasta que se lo restablezcan (ADR-050 §1, «Transicion»), y
    // el quiosco no le bloquea (regla dura 19).
    $escenario = AttendanceFixtures::scenario();
    EmployeePins::issue($escenario['employee'], PortalLogins::PIN);

    ponerLongitudDelPin('8');

    FrozenTime::at('2026-10-06 07:00:00');
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-10-06T07:00:00Z',
            'employee_code' => EmployeePins::codeOf($escenario['employee']),
            'pin_sealed' => EmployeePins::seal(PortalLogins::PIN, EmployeePins::configureSealing()),
        ]);

    $respuesta->assertOk()->assertValidRequest()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('employees')->where('uuid', $escenario['employee'])->value('pin_length'))->toBe(6);
})->group('RF-ID-09', 'RF-AT-11');

it('no saca pin_length por la ficha del empleado', function (): void {
    // Decir que companeros tienen el PIN corto es decir a quien atacar (ADR-050).
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());
    EmployeePins::issue($uuid, PortalLogins::PIN);

    $ficha = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/employees/'.$uuid)
        ->assertValidResponse(200);

    expect($ficha->getContent())->not->toContain('pin_length');
})->group('RF-ID-09', 'RS-05');
