<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Compliance\RecordingIncidentResolutionMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * **La `discarded_scan` sin persona en la bandeja** (RN-22, ADR-061 A2),
 * validada contra el contrato: `employee` es `null`, el quiosco va en el
 * `context`, el administrador la ve y la resuelve, y un responsable de
 * departamento no la ve (no es de nadie de su departamento, RF-ID-03).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
    FrozenTime::at('2026-08-16 08:00:00');
    app()->instance(IncidentResolutionMetrics::class, new RecordingIncidentResolutionMetrics);
});

/**
 * @return array{id: int, admin: string, responsable: string, deviceUuid: string}
 */
function incidenciaSinPersona(): array
{
    $escenario = AttendanceFixtures::scenario();
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    $id = DB::table('incidents')->insertGetId([
        'employee_id' => null,
        'device_id' => $escenario['device'],
        'work_date' => '2026-08-15',
        'shift_entry_id' => null,
        'type' => 'discarded_scan',
        'severity' => 'medium',
        'status' => 'open',
        'assigned_to_user_id' => $admin->id,
        'detected_at' => '2026-08-16 03:30:00+00',
        'context' => json_encode([
            'scan_id' => '0199f0c2-0000-7000-8000-0000000000c1',
            'occurred_at' => '2026-08-15T07:00:00.000000Z',
            'device_uuid' => $escenario['deviceUuid'],
            'origin' => 'qr_kiosk',
            'attribution' => 'none',
            'reports' => 1,
            'source' => 'server',
            'attempts' => 10,
        ], JSON_THROW_ON_ERROR),
        'created_at' => '2026-08-16 03:30:00+00',
        'updated_at' => '2026-08-16 03:30:00+00',
    ]);

    return [
        'id' => $id,
        'admin' => ManagementUsers::tokenFor($admin),
        'responsable' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO)),
        'deviceUuid' => $escenario['deviceUuid'],
    ];
}

it('ensena al administrador la incidencia sin persona con employee nulo y el quiosco en el context', function (): void {
    $caso = incidenciaSinPersona();

    $respuesta = Api::as($caso['admin'])->get('/api/v1/incidents');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('data.0.id'))->toBe($caso['id'])
        ->and($respuesta->json('data.0.employee'))->toBeNull()
        ->and($respuesta->json('data.0.context.device_uuid'))->toBe($caso['deviceUuid'])
        ->and($respuesta->json('meta.total'))->toBe(1);
})->group('RN-22', 'RF-PA-05');

it('no la ensena a un responsable de departamento', function (): void {
    $caso = incidenciaSinPersona();

    $respuesta = Api::as($caso['responsable'])->get('/api/v1/incidents');

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta.total'))->toBe(0);
})->group('RN-22', 'RF-ID-03');

it('deja al administrador resolverla y le niega al responsable', function (): void {
    $caso = incidenciaSinPersona();
    $cuerpo = ['outcome' => 'resolved', 'note' => 'Cruzado con el cuadrante: era la entrada de recepcion.'];

    Api::as($caso['responsable'])->post('/api/v1/incidents/'.$caso['id'].'/resolve', $cuerpo)->assertForbidden();

    Api::as($caso['admin'])->post('/api/v1/incidents/'.$caso['id'].'/resolve', $cuerpo)
        ->assertOk()
        ->assertValidResponse()
        ->assertJsonPath('employee', null)
        ->assertJsonPath('status', 'resolved');
})->group('RN-22', 'RF-PA-05', 'RF-ID-03');
