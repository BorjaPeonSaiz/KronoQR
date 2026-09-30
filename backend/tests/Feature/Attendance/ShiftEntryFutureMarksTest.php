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
 * F1 (RL-01, RL-04): el alta y la correccion manuales no admiten horas futuras.
 *
 * Un registro horario anota lo que ya ha ocurrido. Antes de esto el panel dejaba
 * rellenar la jornada teorica por adelantado o cerrar un turno con la salida
 * «prevista», y esas horas valian para la nomina igual que las fichadas. El
 * limite es la hora del servidor mas `ATTENDANCE_FUTURE_TOLERANCE_MINUTES` (5
 * de serie), y cada rechazo es un `422` colgado del campo, contra el contrato.
 *
 * El reloj se detiene con `FrozenTime` a las 10:00 UTC del 14 de marzo de 2026
 * —las 11:00 en Madrid—: sin eso, «futuro» dependeria del dia en que corre la
 * CI.
 */

uses(RefreshDatabase::class);

const SHIFT_FUTURE_NOW = '2026-03-14 10:00:00';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at(SHIFT_FUTURE_NOW);
});

/**
 * @return array{token: string, employee: string}
 */
function contextoHorasFuturas(): array
{
    $site = WorkforceFixtures::site('Hotel de horas futuras');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employee' => WorkforceFixtures::employee($site),
    ];
}

/**
 * @param  array<string, string|null>  $cambios
 * @return array<string, string|null>
 */
function altaHorasFuturas(string $employee, array $cambios = []): array
{
    return [
        'employee_uuid' => $employee,
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14T06:00:00Z',
        'clocked_out_at' => '2026-03-14T09:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ...$cambios,
    ];
}

it('rechaza con 422 un alta cuya salida todavia no ha ocurrido', function (): void {
    $contexto = contextoHorasFuturas();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], [
            'clocked_out_at' => '2026-03-14T14:00:00Z',
        ]))
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.clocked_out_at.0', 'Esa hora todavía no ha llegado: no se pueden registrar horas futuras. '
            .'Se admite un margen de 5 minuto(s) sobre la hora del servidor.');

    // Nada a medias: ni tramo, ni fila de correccion, ni asiento.
    expect(DB::table('shift_entries')->count())->toBe(0)
        ->and(DB::table('shift_corrections')->count())->toBe(0);
})->group('RF-PA-04', 'RL-04');

it('rechaza con 422 la jornada teorica rellenada por adelantado', function (): void {
    $contexto = contextoHorasFuturas();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], [
            'work_date' => '2026-03-16',
            'clocked_in_at' => '2026-03-16T06:00:00Z',
            'clocked_out_at' => '2026-03-16T14:00:00Z',
        ]))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.work_date.0', 'Esa jornada todavía no ha empezado: no se pueden registrar horas de un día futuro.');

    expect(DB::table('shift_entries')->count())->toBe(0);
})->group('RF-PA-04', 'RL-04');

it('rechaza la entrada futura de un tramo abierto', function (): void {
    $contexto = contextoHorasFuturas();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], [
            'clocked_in_at' => '2026-03-14T12:00:00Z',
            'clocked_out_at' => null,
        ]))
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['clocked_in_at']]);
})->group('RF-PA-04', 'RL-04');

it('admite la salida dentro del margen, que es el redondeo al minuto del formulario', function (): void {
    $contexto = contextoHorasFuturas();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], [
            'clocked_out_at' => '2026-03-14T10:05:00Z',
        ]))
        ->assertValidResponse(201)
        ->assertJsonPath('daily_total_minutes', 245);
})->group('RF-PA-04', 'RL-04');

it('lee el margen de la configuracion de la instalacion y no de una constante', function (): void {
    // Regla dura 13: el margen es configuracion. Con cero, un minuto por delante
    // del servidor ya es futuro.
    DB::table('installation_settings')->insert([
        'key' => 'ATTENDANCE_FUTURE_TOLERANCE_MINUTES',
        'value' => '0',
        'updated_at' => '2026-03-01 00:00:00+00',
    ]);
    $contexto = contextoHorasFuturas();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], [
            'clocked_out_at' => '2026-03-14T10:01:00Z',
        ]))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.clocked_out_at.0', 'Esa hora todavía no ha llegado: no se pueden registrar horas futuras. '
            .'Se admite un margen de 0 minuto(s) sobre la hora del servidor.');
})->group('RF-PA-04', 'RF-PD-01');

it('rechaza cerrar un turno abierto con la hora a la que va a salir', function (): void {
    $contexto = contextoHorasFuturas();

    $abierto = Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], ['clocked_out_at' => null]))
        ->assertValidResponse(201)
        ->json('shift_entry_uuid');

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$abierto, [
            'clocked_out_at' => '2026-03-14T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['clocked_out_at']]);

    // El tramo sigue abierto y sin version nueva: la correccion no se aplico.
    expect(DB::table('shift_entries')->count())->toBe(1)
        ->and(DB::table('shift_entries')->value('clocked_out_at'))->toBeNull()
        ->and(DB::table('shift_corrections')->count())->toBe(1);
})->group('RF-PA-04', 'RN-13', 'RL-04');

it('rechaza mover a futuro la entrada de un tramo abierto', function (): void {
    $contexto = contextoHorasFuturas();

    $abierto = Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], ['clocked_out_at' => null]))
        ->assertValidResponse(201)
        ->json('shift_entry_uuid');

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$abierto, [
            'clocked_in_at' => '2026-03-14T11:00:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['clocked_in_at']]);
})->group('RF-PA-04', 'RL-04');

it('responde el rechazo en ingles si el panel lo pide en ingles', function (): void {
    $contexto = contextoHorasFuturas();

    Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'en'])
        ->post('/api/v1/shift-entries', altaHorasFuturas($contexto['employee'], [
            'clocked_out_at' => '2026-03-14T14:00:00Z',
        ]))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.clocked_out_at.0', 'That time has not happened yet: future hours cannot be recorded. '
            .'A margin of 5 minute(s) over the server clock is allowed.');
})->group('RF-PA-04');
