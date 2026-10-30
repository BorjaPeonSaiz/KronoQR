<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
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
 * - Una readmision y la purga de retencion a la vez (ADR-060): o se readmite
 *   la ficha entera, o se minimiza la baja y la readmision responde `409`
 *   `employee-record-minimised`.
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

it('la readmision y la purga de retencion a la vez: o se readmite una ficha entera, o se minimiza una baja', function (): void {
    // ADR-062 §6, ADR-060: la purga toma la cadena antes que la ficha, como la
    // readmision. O minimiza antes y la readmision responde 409
    // employee-record-minimised, o espera y ya no encuentra una baja que
    // minimizar. Nunca una persona en alta minimizada, ni una readmision de una
    // ficha minimizada.
    $directorio = sys_get_temp_dir().'/kronoqr-rehire-retention-'.bin2hex(random_bytes(6));
    Config::set('compliance.retention.report_path', $directorio.'/informes');
    Config::set('compliance.retention.technical_log_path', $directorio.'/logs');
    Config::set('observability.metrics.textfile_path', $directorio.'/metricas');
    Config::set('compliance.retention.personnel_photo_root', $directorio.'/fotos-ficha');

    // El reloj de la aplicacion, el dia de hoy de la base: la purga no admite
    // un corte posterior al que calcula PostgreSQL con su propio reloj.
    /** @var object{today: string} $hoy */
    $hoy = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");
    FrozenTime::at($hoy->today.' 04:00:00');

    $escenario = AttendanceFixtures::scenario();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $responsable = ManagementUsers::withRole(UserRole::RRHH);
    $desenlaces = [];

    for ($ronda = 0; $ronda < REHIRE_RACE_ROUNDS; $ronda++) {
        $persona = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated', hiredAt: '2010-01-01', terminatedAt: '2015-06-30');
        $id = AttendanceFixtures::employeeIdOf($persona);
        $confirmacion = app(PlanRetention::class)->handle(app(Clock::class)->now())->confirmationToken();
        soltarRedisAntesDeLaCarrera();

        // La orden de consola tarda mas en arrancar que la peticion: sin un
        // retraso la readmision gana siempre. Con uno distinto en cada ronda,
        // las dos se cruzan en un orden y en el otro.
        $retraso = [0, 400_000, 900_000, 1_500_000][$ronda % 4];

        $resultados = ParallelRequests::runTasks(2, static function (int $i) use ($retraso, $token, $persona, $hoy, $confirmacion, $responsable): int {
            if ($i === 0) {
                usleep($retraso);

                return Api::as($token)->post('/api/v1/employees/'.$persona.'/rehire', ['hired_at' => $hoy->today])->getStatusCode();
            }

            return Artisan::call('compliance:apply-retention', [
                '--confirm' => $confirmacion,
                '--responsible' => $responsable->email,
                '--responsible-confirm' => $responsable->email,
                '--no-interaction' => true,
            ]);
        });

        /** @var object{status: string, anonymized_at: string|null} $ficha */
        $ficha = DB::table('employees')->where('id', $id)->first(['status', 'anonymized_at']);
        $periodos = DB::table('employment_periods')->where('employee_id', $id)->count();
        $readmisiones = DB::table('audit_log')->where('action', 'employee.rehired')->where('payload->employee_uuid', $persona)->count();

        if ($resultados[0] === 200) {
            $desenlaces[] = 'readmitida';
            expect($ficha->status)->toBe('active')
                ->and($ficha->anonymized_at)->toBeNull()
                ->and($periodos)->toBe(2)
                ->and($readmisiones)->toBe(1);
        } else {
            $desenlaces[] = 'minimizada';
            expect($resultados[0])->toBe(409)
                ->and($ficha->status)->toBe('terminated')
                ->and($ficha->anonymized_at)->not->toBeNull()
                ->and($periodos)->toBe(1)
                ->and($readmisiones)->toBe(0);
        }
    }

    expect($desenlaces)->toHaveCount(REHIRE_RACE_ROUNDS);
})->group('RN-27', 'RL-11');
