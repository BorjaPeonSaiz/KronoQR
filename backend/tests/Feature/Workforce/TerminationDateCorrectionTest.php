<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * **LA CORRECCION AUDITADA DE LA FECHA DE CESE** (RN-27, RN-13, RL-04,
 * RF-PA-04, ADR-062 §7 y §10).
 *
 * «Omar» causo baja con fecha de cese el 15 de octubre y trabajo de verdad hasta
 * el 18. RRHH corrige la fecha con motivo: el periodo termina el 18, se conserva
 * la fecha anterior con su autor, su momento y su motivo, el asiento no lleva el
 * motivo, los dias que gana el periodo admiten tramos a mano y las tarjetas
 * siguen revocadas. Hoy es el 30 de octubre en Madrid.
 */

uses(RefreshDatabase::class);

const TERMINATION_CORRECTION_REASON = 'Trabajo hasta el 18 segun el cuadrante firmado';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function tokenDeLaCorreccionDelCese(UserRole $role = UserRole::RRHH): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole($role));
}

/**
 * @return array{employee: string, period: string, employeeId: int}
 */
function omarDeBajaDesdeEl15(): array
{
    FrozenTime::at('2026-10-30 09:00:00');
    $escenario = AttendanceFixtures::scenario();
    $id = AttendanceFixtures::employeeIdOf($escenario['employee']);
    Credentials::issueFor($id);

    Api::as(tokenDeLaCorreccionDelCese())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-10-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated');

    /** @var string $period */
    $period = DB::table('employment_periods')->where('employee_id', $id)->value('uuid');

    return ['employee' => $escenario['employee'], 'period' => $period, 'employeeId' => $id];
}

it('corrige la fecha de cese con motivo, conserva la anterior y deja un asiento sin el motivo', function (): void {
    $omar = omarDeBajaDesdeEl15();

    Api::as(tokenDeLaCorreccionDelCese())
        ->post('/api/v1/employees/'.$omar['employee'].'/employment-periods/'.$omar['period'].'/termination-corrections', [
            'terminated_at' => '2026-10-18',
            'reason' => TERMINATION_CORRECTION_REASON,
        ])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employment_period_uuid', $omar['period'])
        ->assertJsonPath('previous_terminated_at', '2026-10-15')
        ->assertJsonPath('terminated_at', '2026-10-18');

    expect(DB::table('employees')->where('uuid', $omar['employee'])->first(['status', 'terminated_at']))
        ->toEqual((object) ['status' => 'terminated', 'terminated_at' => '2026-10-18'])
        ->and(DB::table('employment_periods')->where('uuid', $omar['period'])->value('terminated_on'))->toBe('2026-10-18')
        ->and(DB::table('employment_period_corrections')->first(['previous_terminated_on', 'terminated_on', 'reason']))
        ->toEqual((object) ['previous_terminated_on' => '2026-10-15', 'terminated_on' => '2026-10-18', 'reason' => TERMINATION_CORRECTION_REASON])
        // Las tarjetas no se reactivan.
        ->and(DB::table('credentials')->where('employee_id', $omar['employeeId'])->whereNull('revoked_at')->count())->toBe(0);

    /** @var string $payload */
    $payload = DB::table('audit_log')->where('action', 'employee.termination_date_corrected')->value('payload');

    expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
        'employee_uuid' => $omar['employee'],
        'employment_period_uuid' => $omar['period'],
        'previous_terminated_on' => '2026-10-15',
        'terminated_on' => '2026-10-18',
    ])
        ->and($payload)->not->toContain('cuadrante');
})->group('RN-27', 'RN-13', 'RL-04');

it('los dias que gana el periodo admiten tramos a mano', function (): void {
    $omar = omarDeBajaDesdeEl15();
    $token = tokenDeLaCorreccionDelCese();
    $tramo = [
        'employee_uuid' => $omar['employee'],
        'work_date' => '2026-10-18',
        'clocked_in_at' => '2026-10-18T06:00:00Z',
        'clocked_out_at' => '2026-10-18T14:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
    ];

    Api::as($token)->post('/api/v1/shift-entries', $tramo)->assertValidResponse(422);

    Api::as($token)
        ->post('/api/v1/employees/'.$omar['employee'].'/employment-periods/'.$omar['period'].'/termination-corrections', [
            'terminated_at' => '2026-10-18',
            'reason' => TERMINATION_CORRECTION_REASON,
        ])
        ->assertValidResponse(201);

    Api::as($token)->post('/api/v1/shift-entries', $tramo)->assertValidResponse(201);
})->group('RN-27', 'RF-PA-04');

it('rechaza una fecha posterior a hoy, anterior al alta o un motivo corto con 422 y sin escribir nada', function (array $cuerpo, string $campo): void {
    $omar = omarDeBajaDesdeEl15();

    Api::as(tokenDeLaCorreccionDelCese())
        ->post('/api/v1/employees/'.$omar['employee'].'/employment-periods/'.$omar['period'].'/termination-corrections', $cuerpo)
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor($campo);

    expect(DB::table('employment_period_corrections')->count())->toBe(0)
        ->and(DB::table('employees')->where('uuid', $omar['employee'])->value('terminated_at'))->toBe('2026-10-15')
        ->and(DB::table('audit_log')->where('action', 'employee.termination_date_corrected')->count())->toBe(0);
})->with([
    'posterior a hoy' => [['terminated_at' => '2026-10-31', 'reason' => TERMINATION_CORRECTION_REASON], 'terminated_at'],
    'anterior al alta' => [['terminated_at' => '2025-12-31', 'reason' => TERMINATION_CORRECTION_REASON], 'terminated_at'],
    'la misma fecha' => [['terminated_at' => '2026-10-15', 'reason' => TERMINATION_CORRECTION_REASON], 'terminated_at'],
    'motivo corto' => [['terminated_at' => '2026-10-18', 'reason' => 'Corta'], 'reason'],
])->group('RN-27');

it('una baja programada no se corrige: 409 offboarding-not-effective', function (): void {
    FrozenTime::at('2026-10-30 09:00:00');
    $escenario = AttendanceFixtures::scenario();

    Api::as(tokenDeLaCorreccionDelCese())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-11-30'])
        ->assertValidResponse(200);

    /** @var string $period */
    $period = DB::table('employment_periods')->where('employee_id', AttendanceFixtures::employeeIdOf($escenario['employee']))->value('uuid');

    Api::as(tokenDeLaCorreccionDelCese())
        ->post('/api/v1/employees/'.$escenario['employee'].'/employment-periods/'.$period.'/termination-corrections', [
            'terminated_at' => '2026-10-29',
            'reason' => TERMINATION_CORRECTION_REASON,
        ])
        ->assertValidResponse(409)
        ->assertJsonPath('type', ProblemDetails::TYPE_OFFBOARDING_NOT_EFFECTIVE);
})->group('RN-27');

it('un periodo que no es de esa persona responde 404', function (): void {
    $omar = omarDeBajaDesdeEl15();

    Api::as(tokenDeLaCorreccionDelCese())
        ->post('/api/v1/employees/'.$omar['employee'].'/employment-periods/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91/termination-corrections', [
            'terminated_at' => '2026-10-18',
            'reason' => TERMINATION_CORRECTION_REASON,
        ])
        ->assertValidResponse(404);
})->group('RN-27');

it('corrige el cese de un periodo anterior a una readmision sin alcanzar el siguiente', function (): void {
    $omar = omarDeBajaDesdeEl15();
    $token = tokenDeLaCorreccionDelCese();

    Api::as($token)
        ->post('/api/v1/employees/'.$omar['employee'].'/rehire', ['hired_at' => '2026-10-25'])
        ->assertValidResponse(200);

    Api::as($token)
        ->post('/api/v1/employees/'.$omar['employee'].'/employment-periods/'.$omar['period'].'/termination-corrections', [
            'terminated_at' => '2026-10-25',
            'reason' => TERMINATION_CORRECTION_REASON,
        ])
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor('terminated_at');

    Api::as($token)
        ->post('/api/v1/employees/'.$omar['employee'].'/employment-periods/'.$omar['period'].'/termination-corrections', [
            'terminated_at' => '2026-10-24',
            'reason' => TERMINATION_CORRECTION_REASON,
        ])
        ->assertValidResponse(201);

    // La ficha sigue en alta con su periodo nuevo: la correccion fue del anterior.
    expect(DB::table('employees')->where('uuid', $omar['employee'])->first(['status', 'hired_at', 'terminated_at']))
        ->toEqual((object) ['status' => 'active', 'hired_at' => '2026-10-25', 'terminated_at' => null]);
})->group('RN-27');
