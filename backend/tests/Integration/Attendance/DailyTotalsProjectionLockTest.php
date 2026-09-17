<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ProjectedDailyTotal;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DatabaseDailyTotalsProjection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El candado de `lockedFor()` es de verdad** (RF-PR-02, RN-06, tarea 3.6).
 *
 * POR QUE ESTA PRUEBA NO PODIA SER UNITARIA NI DE FEATURE. Lo que se comprueba
 * es una garantia del motor: que `SELECT … FOR UPDATE OF d` toma la fila de
 * `daily_totals` y **no** la del empleado. Un doble en memoria no puede
 * bloquearse a si mismo, y desde una prueba de feature se veria el efecto
 * —ninguna jornada corrompida— sin poder decir si lo consigue el candado o la
 * suerte del planificador.
 *
 * DE ESTO DEPENDE LA CORRECCION ENTERA. La reconciliacion relee la jornada
 * despues de tomar la fila: si el candado no serializara con el `UPSERT` del
 * fichaje, la relectura seguiria pudiendo quedarse vieja y volveria la
 * corrupcion que destapo la prueba de carga (`ReconciliationConcurrencyTest`).
 *
 * **Dos sesiones y no dos procesos.** Basta con dos conexiones a la misma base:
 * lo que se afirma es quien espera a quien, y `NOWAIT` lo responde sin depender
 * de ningun tiempo — una prueba que midiera milisegundos seria una prueba que
 * falla sola en la CI. Por eso tampoco hay `RefreshDatabase`: su transaccion
 * envolvente esconderia las filas a la segunda conexion.
 */

uses(CommittedDatabase::class);

/** La conexion de la segunda sesion, creada a partir de la de la aplicacion. */
function lockProbeSession(): ConnectionInterface
{
    config(['database.connections.lock_probe' => config()->array('database.connections.pgsql')]);

    return DB::connection('lock_probe');
}

/**
 * Centro, empleado y una fila de `daily_totals` ya escrita.
 *
 * @return array{employee: string, employeeId: int}
 */
function lockScenario(): array
{
    $site = WorkforceFixtures::site('Hotel del candado', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos'));
    $employeeId = AttendanceFixtures::employeeIdOf($employee);

    DB::table('daily_totals')->insert([
        'employee_id' => $employeeId,
        'work_date' => '2026-03-14',
        'total_minutes' => 480,
        'shift_count' => 1,
        'first_in_at' => '2026-03-14 06:00:00+00',
        'last_out_at' => '2026-03-14 14:00:00+00',
        'has_open_shift' => false,
        'has_incident' => false,
        'recalculated_at' => '2026-03-14 14:00:05+00',
    ]);

    return ['employee' => $employee, 'employeeId' => $employeeId];
}

function lockWorkDate(): WorkDate
{
    return WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid'));
}

/**
 * Intenta tomar esa fila **sin esperar**: si alguien la tiene, PostgreSQL falla
 * en el acto (`55P03`) en lugar de quedarse colgado.
 */
function tryToLock(ConnectionInterface $session, string $sql, int $employeeId): mixed
{
    return $session->select($sql, [$employeeId]);
}

afterEach(function (): void {
    // La conexion de sondeo no puede sobrevivir a la prueba: `ParallelRequests`
    // cierra «todas las conexiones abiertas» antes de bifurcar y una conexion
    // fantasma en el gestor es exactamente la clase de herencia que documenta su
    // cabecera.
    DB::purge('lock_probe');
});

it('deja la fila de la jornada tomada hasta que la transaccion confirma', function (): void {
    $scenario = lockScenario();
    $probe = lockProbeSession();
    $projection = new DatabaseDailyTotalsProjection(DB::connection());

    DB::connection()->transaction(function () use ($projection, $probe, $scenario): void {
        $row = $projection->lockedFor($scenario['employee'], lockWorkDate());

        // Primero: la lectura sigue siendo una lectura y devuelve la fila tal
        // como esta escrita.
        expect($row)->toBeInstanceOf(ProjectedDailyTotal::class)
            ->and($row?->totalMinutes)->toBe(480)
            ->and($row?->shiftCount)->toBe(1);

        // Y despues lo que importa: la otra sesion NO puede tomarla. Es lo que
        // obliga al `UPSERT` de un fichaje simultaneo a esperar y a reescribir
        // luego con sus propios valores, que son los buenos.
        expect(fn (): mixed => tryToLock(
            $probe,
            "SELECT 1 FROM daily_totals WHERE employee_id = ? AND work_date = '2026-03-14' FOR UPDATE NOWAIT",
            $scenario['employeeId'],
        ))->toThrow(QueryException::class);
    });

    // Confirmada la transaccion, la fila vuelve a estar libre: el candado dura lo
    // que dura la correccion y ni un milisegundo mas.
    $freeAgain = tryToLock(
        $probe,
        "SELECT total_minutes FROM daily_totals WHERE employee_id = ? AND work_date = '2026-03-14' FOR UPDATE NOWAIT",
        $scenario['employeeId'],
    );

    expect($freeAgain)->toHaveCount(1);
})->group('RF-PR-02', 'RN-06');

it('no bloquea la ficha del empleado, que es de otro modulo', function (): void {
    // `FOR UPDATE OF d` y no `FOR UPDATE` a secas. La union con `employees` esta
    // solo para traducir el UUID publico a la clave foranea: bloquearla ademas
    // dejaria al alta de personal esperando por una reconciliacion nocturna, y
    // por un motivo que no tiene nada que ver con ella.
    $scenario = lockScenario();
    $probe = lockProbeSession();
    $projection = new DatabaseDailyTotalsProjection(DB::connection());

    DB::connection()->transaction(function () use ($projection, $probe, $scenario): void {
        $projection->lockedFor($scenario['employee'], lockWorkDate());

        $employeeRow = tryToLock(
            $probe,
            'SELECT id FROM employees WHERE id = ? FOR UPDATE NOWAIT',
            $scenario['employeeId'],
        );

        expect($employeeRow)->toHaveCount(1);
    });
})->group('RF-PR-02', 'RN-06');

it('no toma ningun candado cuando la jornada no tiene fila', function (): void {
    // El residuo declarado en el caso de uso: una fila que todavia no existe no
    // se puede bloquear. La prueba fija la consecuencia —devuelve `null` y no
    // inventa nada— para que quien venga a cerrarla sepa exactamente que estaba
    // pasando antes.
    $scenario = lockScenario();
    $projection = new DatabaseDailyTotalsProjection(DB::connection());

    DB::connection()->transaction(function () use ($projection, $scenario): void {
        $anotherDay = $projection->lockedFor(
            $scenario['employee'],
            WorkDate::fromIsoDate('2026-03-15', new DateTimeZone('Europe/Madrid')),
        );

        expect($anotherDay)->toBeNull();
    });
})->group('RF-PR-02');
