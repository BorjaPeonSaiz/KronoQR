<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA READMISION CONTRA SI MISMA Y CONTRA LA EMISION DE UNA TARJETA** (RN-27,
 * RN-14, ADR-062 §6 y §Verificacion, ADR-046 §1).
 *
 * Procesos de verdad sobre una base confirmada. Criterio de ADR-062: cero
 * `40P01`, una sola readmision por persona y ninguna persona con dos tarjetas
 * activas ni de baja con una activa.
 *
 * - Dos readmisiones de la misma persona a la vez: el candado de la ficha las
 *   serializa; la segunda ve la ficha ya en alta y responde `409`.
 * - Una readmision y la emision de una tarjeta a la vez: si la emision llega
 *   primero, ve la baja y responde `409` (RN-14); si llega despues, la persona ya
 *   tiene su tarjeta nueva pendiente y responde `409` igual.
 */

uses(CommittedDatabase::class);

const REHIRE_RACE_ROUNDS = 4;

/** Una persona de baja desde el 30 de junio, sin tarjeta activa. */
function personaDeBajaParaLaCarrera(int $site, int $department): string
{
    return WorkforceFixtures::employee($site, $department, 'terminated');
}

/** Sin sockets heredados al bifurcar (ver `HeartbeatConcurrencyTest`). */
function soltarRedisAntesDeLaCarrera(): void
{
    app(RedisManager::class)->purge();
}

/**
 * @param  list<array{status: int, body: mixed}>  $respuestas
 * @return list<int>
 */
function estadosDeLaCarrera(array $respuestas): array
{
    $estados = array_map(static fn (array $r): int => $r['status'], $respuestas);
    sort($estados);

    return $estados;
}

it('dos readmisiones de la misma persona a la vez dejan una sola, sin abrazo mortal', function (): void {
    FrozenTime::at('2027-04-01 08:00:00');
    $escenario = AttendanceFixtures::scenario();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    for ($ronda = 0; $ronda < REHIRE_RACE_ROUNDS; $ronda++) {
        $persona = personaDeBajaParaLaCarrera($escenario['site'], $escenario['department']);
        soltarRedisAntesDeLaCarrera();

        $respuestas = ParallelRequests::run(2, static fn (int $i) => Api::as($token)
            ->post('/api/v1/employees/'.$persona.'/rehire', ['hired_at' => $i === 0 ? '2027-04-01' : '2027-04-02']));

        $id = AttendanceFixtures::employeeIdOf($persona);

        expect(estadosDeLaCarrera($respuestas))->toBe([200, 409])
            ->and(DB::table('employment_periods')->where('employee_id', $id)->count())->toBe(2)
            ->and(DB::table('credentials')->where('employee_id', $id)->whereNull('revoked_at')->count())->toBe(1)
            ->and(DB::table('audit_log')->where('action', 'employee.rehired')->where('payload->employee_uuid', $persona)->count())->toBe(1);
    }
})->group('RN-27');

it('la readmision y la emision de una tarjeta a la vez no dejan dos tarjetas ni una baja con tarjeta', function (): void {
    FrozenTime::at('2027-04-01 08:00:00');
    $escenario = AttendanceFixtures::scenario();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    for ($ronda = 0; $ronda < REHIRE_RACE_ROUNDS; $ronda++) {
        $persona = personaDeBajaParaLaCarrera($escenario['site'], $escenario['department']);
        soltarRedisAntesDeLaCarrera();

        $respuestas = ParallelRequests::run(2, static fn (int $i) => $i === 0
            ? Api::as($token)->post('/api/v1/employees/'.$persona.'/rehire', ['hired_at' => '2027-04-01'])
            : Api::as($token)->post('/api/v1/credentials', ['employee_uuid' => $persona]));

        $id = AttendanceFixtures::employeeIdOf($persona);

        expect($respuestas[0]['status'])->toBe(200)
            ->and($respuestas[1]['status'])->toBe(409)
            ->and(DB::table('employees')->where('id', $id)->value('status'))->toBe('active')
            ->and(DB::table('credentials')->where('employee_id', $id)->whereNull('revoked_at')->count())->toBe(1);
    }
})->group('RN-27', 'RN-14');
