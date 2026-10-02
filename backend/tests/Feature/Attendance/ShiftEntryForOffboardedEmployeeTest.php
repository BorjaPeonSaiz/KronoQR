<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LOS DIAS TRABAJADOS HASTA EL CESE SE COMPLETAN A MANO TAMBIEN TRAS LA BAJA**
 * (RN-14, RF-PA-04, 2.2.0; R-1 del dictamen de seguridad sobre R4-SC-01).
 *
 * La baja es efectiva al registrarla y corta el fichaje en el acto. Un olvido de
 * los ultimos dias, o un fichaje de la cola offline que llega despues de la
 * baja, solo pueden entrar por el alta manual. Por eso una persona dada de baja
 * admite tramos de jornadas entre su alta y su cese, ambas incluidas, con su
 * fila de `shift_corrections` y su asiento como cualquier otro; fuera de ese
 * periodo, `422` en `work_date`. El escaneo del quiosco no cambia.
 *
 * La persona de las pruebas esta en alta desde el 2026-01-01 y de baja desde el
 * 2026-06-30 (`WorkforceFixtures`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at('2026-10-02 10:00:00');
});

/**
 * @return array{token: string, employee: string}
 */
function contextoDelTramoDeUnaBaja(string $status = 'terminated'): array
{
    $site = WorkforceFixtures::site('Hotel de los dias que faltaban');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employee' => WorkforceFixtures::employee($site, null, $status),
    ];
}

/**
 * @return array<string, string>
 */
function tramoDeUnaBaja(string $employee, string $workDate): array
{
    return [
        'employee_uuid' => $employee,
        'work_date' => $workDate,
        'clocked_in_at' => $workDate.'T06:00:00Z',
        'clocked_out_at' => $workDate.'T14:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
    ];
}

it('admite el tramo de la jornada del cese de una persona de baja, con su correccion y su asiento', function (): void {
    $contexto = contextoDelTramoDeUnaBaja();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', tramoDeUnaBaja($contexto['employee'], '2026-06-30'))
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('work_date', '2026-06-30')
        ->assertJsonPath('action', 'created')
        ->assertJsonPath('daily_total_minutes', 480);

    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_corrections')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'shift_entry.created')->count())->toBe(1);
})->group('RN-14', 'RF-PA-04', 'RL-04');

it('admite el tramo de la jornada del alta de una persona de baja', function (): void {
    $contexto = contextoDelTramoDeUnaBaja();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', tramoDeUnaBaja($contexto['employee'], '2026-01-01'))
        ->assertValidResponse(201);
})->group('RN-14', 'RF-PA-04');

it('rechaza con 422 en work_date la jornada posterior al cese y no escribe nada', function (): void {
    $contexto = contextoDelTramoDeUnaBaja();
    $asientos = DB::table('audit_log')->count();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', tramoDeUnaBaja($contexto['employee'], '2026-07-01'))
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.work_date.0', 'Esta persona está de baja desde el 2026-06-30: solo se le pueden '
            .'registrar horas de jornadas entre su alta (2026-01-01) y su cese (2026-06-30).');

    expect(DB::table('shift_entries')->count())->toBe(0)
        ->and(DB::table('shift_corrections')->count())->toBe(0)
        ->and(DB::table('audit_log')->count())->toBe($asientos);
})->group('RN-14', 'RF-PA-04');

it('rechaza la jornada anterior al alta de una persona de baja, tambien en ingles', function (): void {
    $contexto = contextoDelTramoDeUnaBaja();

    Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->post('/api/v1/shift-entries', tramoDeUnaBaja($contexto['employee'], '2025-12-31'))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.work_date.0', 'This person has been offboarded since 2026-06-30: hours can only be '
            .'recorded for working days between their start date (2026-01-01) and their termination date (2026-06-30).');

    expect(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-14', 'RF-PA-04');

it('sigue rechazando en employee_uuid a una persona suspendida', function (): void {
    $contexto = contextoDelTramoDeUnaBaja('suspended');

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', tramoDeUnaBaja($contexto['employee'], '2026-06-30'))
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['employee_uuid']]);

    expect(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-14', 'RF-PA-04');
