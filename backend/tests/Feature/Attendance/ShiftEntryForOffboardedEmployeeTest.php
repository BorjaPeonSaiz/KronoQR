<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\InstallationLocale;
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
    // Con el ingles activo: de serie solo lo esta el español (bloque 26).
    InstallationLocale::set('es', ['es', 'en']);
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
})->group('RN-25', 'RN-14', 'RF-PA-04');

// --- RN-05 (2.3.0): las horas escritas a mano no pasan del cese --------------

/**
 * Escenario «Las horas escritas a mano no pasan del cese» del doc 01 §11: Elena,
 * de baja con cese el 10 de octubre y un tramo de 08:00 a 15:00 (hora de
 * Madrid, CEST) en su jornada del 10. El reloj se para el 20 para que ninguna
 * de estas horas caiga en el futuro (F1).
 *
 * @return array{token: string, employee: string, tramo: string}
 */
function contextoDeLaCotaDelCese(): array
{
    FrozenTime::at('2026-10-20 10:00:00');
    $contexto = contextoDelTramoDeUnaBaja();
    DB::table('employees')->where('uuid', $contexto['employee'])->update(['terminated_at' => '2026-10-10']);

    $tramo = Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $contexto['employee'],
            'work_date' => '2026-10-10',
            'clocked_in_at' => '2026-10-10T06:00:00Z',
            'clocked_out_at' => '2026-10-10T13:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertValidResponse(201)
        ->json('shift_entry_uuid');

    \assert(\is_string($tramo));

    return [...$contexto, 'tramo' => $tramo];
}

/** @return array{entries: int, corrections: int, audit: int} */
function filasDeLaCotaDelCese(): array
{
    return [
        'entries' => DB::table('shift_entries')->count(),
        'corrections' => DB::table('shift_corrections')->count(),
        'audit' => DB::table('audit_log')->count(),
    ];
}

it('rechaza dar de alta en la jornada del cese un tramo que entra tres dias despues, sin escribir nada', function (): void {
    $contexto = contextoDeLaCotaDelCese();
    $antes = filasDeLaCotaDelCese();

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $contexto['employee'],
            'work_date' => '2026-10-10',
            'clocked_in_at' => '2026-10-13T06:00:00Z',
            'clocked_out_at' => '2026-10-13T13:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonPath('errors.clocked_in_at.0', 'Esta persona cesó el 2026-10-10: esa hora queda fuera de su '
            .'última jornada. Solo se admiten horas que continúen la jornada del día de cese.')
        ->assertJsonMissingPath('errors.work_date');

    expect(filasDeLaCotaDelCese())->toBe($antes);
})->group('RN-05', 'RN-14', 'RF-PA-04');

it('rechaza corregir el tramo del cese para que entre tres dias despues, sin escribir nada', function (): void {
    $contexto = contextoDeLaCotaDelCese();
    $antes = filasDeLaCotaDelCese();

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$contexto['tramo'], [
            'clocked_in_at' => '2026-10-13T06:00:00Z',
            'clocked_out_at' => '2026-10-13T13:00:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidRequest()
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => ['clocked_in_at']]);

    expect(filasDeLaCotaDelCese())->toBe($antes)
        ->and(DB::table('shift_entries')->where('uuid', $contexto['tramo'])->value('status'))->toBe('closed');
})->group('RN-05', 'RN-14', 'RF-PA-04');

it('admite en la jornada del cese el turno de noche y la vuelta de su pausa de madrugada', function (): void {
    // Regla dura 4: el turno 22:00 -> 02:00 es de la jornada del 10 aunque
    // acabe el 11, y la vuelta de las 02:30 la continua. Las dos marcas quedan
    // antes de la cota del dia del cese (12:00 del 11 en Madrid).
    FrozenTime::at('2026-10-20 10:00:00');
    $contexto = contextoDelTramoDeUnaBaja();
    DB::table('employees')->where('uuid', $contexto['employee'])->update(['terminated_at' => '2026-10-10']);

    // El turno termina en pausa: es lo que deja continuarlo al dia siguiente.
    foreach ([['2026-10-10T20:00:00Z', '2026-10-11T00:00:00Z', 240, true], ['2026-10-11T00:30:00Z', '2026-10-11T04:00:00Z', 450, false]] as [$entrada, $salida, $total, $pausa]) {
        Api::as($contexto['token'])
            ->post('/api/v1/shift-entries', [
                'employee_uuid' => $contexto['employee'],
                'work_date' => '2026-10-10',
                'clocked_in_at' => $entrada,
                'clocked_out_at' => $salida,
                'ends_in_break' => $pausa,
                'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
            ])
            ->assertValidResponse(201)
            ->assertJsonPath('work_date', '2026-10-10')
            ->assertJsonPath('daily_total_minutes', $total);
    }

    expect(DB::table('shift_entries')->where('work_date', '2026-10-10')->count())->toBe(2);
})->group('RN-05', 'RN-14', 'RF-PA-04');
