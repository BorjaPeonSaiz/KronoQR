<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Persistence\AuditChainLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Concurrency\UseCaseInOtherProcess;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **RENOMBRAR UN DEPARTAMENTO MIENTRAS SE DA DE ALTA A ALGUIEN EN EL** no se
 * abraza (ADR-046 §1.1 punto 3, ADR-051 §6; revision del bloque 12c).
 *
 * El alta inserta la ficha —la clave ajena toma `FOR KEY SHARE` sobre el
 * departamento— y despues espera la cadena para su asiento. El renombrado
 * tomaba la fila `FOR NO KEY UPDATE` (compatible con ese `KEY SHARE`), cogia la
 * cadena y su `UPDATE` del nombre —que esta en el indice unico completo
 * `departments_site_id_name_unique`— **subia a `FOR UPDATE` con la cadena en la
 * mano**: esperaba al alta, que esperaba la cadena. `40P01`.
 *
 * La prueba ordena las dos para que el ciclo, si existe, se cierre siempre:
 * una sesion toma la cadena; el renombrado arranca y se queda esperandola; el
 * alta arranca y se queda esperando (la cadena con el codigo viejo, la fila del
 * departamento con la correccion); se suelta la cadena y PostgreSQL se la da al
 * primero de la cola, el renombrado. Las dos tienen que terminar.
 *
 * El alta va dentro de una transaccion de fuera para que `EmployeeWriteRetry`
 * no esconda el `40P01` reintentandolo: por HTTP, la victima puede ser el
 * renombrado, que no reintenta, y eso es un `500`.
 */

uses(CommittedDatabase::class);

const DEPARTMENT_RENAME_DURING_HIRE_NOW = '2026-10-07 10:00:00';

beforeEach(function (): void {
    FrozenTime::at(DEPARTMENT_RENAME_DURING_HIRE_NOW);
});

afterEach(function (): void {
    DB::purge('rename_during_hire_holder');
    DB::purge('rename_during_hire_probe');
    ChildSessions::waitUntilGone();
});

function sesionDelRenombradoConAlta(string $nombre): ConnectionInterface
{
    config(['database.connections.'.$nombre => config()->array('database.connections.pgsql')]);

    return DB::connection($nombre);
}

/**
 * Espera, **por condicion**, a que haya al menos `$cuantas` sesiones de esta base
 * esperando un candado cualquiera, o a que el ultimo proceso arrancado termine
 * sin llegar a esperar.
 */
function esperarSesionesBloqueadas(ConnectionInterface $probe, int $cuantas, UseCaseInOtherProcess $ultimo): bool
{
    for ($attempt = 0; $attempt < 10_000; $attempt++) {
        /** @var object{waiting: int} $row */
        $row = $probe->selectOne(
            'SELECT count(DISTINCT l.pid) AS waiting FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
             WHERE NOT l.granted AND a.datname = current_database()',
        );

        if ($row->waiting >= $cuantas) {
            return true;
        }

        if (! $ultimo->isRunning()) {
            return false;
        }

        usleep(2_000);
    }

    return false;
}

it('renombra el departamento y da de alta a la persona sin abrazo de candados', function (): void {
    $site = WorkforceFixtures::site();
    $departamento = WorkforceFixtures::department($site, 'Pisos');
    $cadena = sesionDelRenombradoConAlta('rename_during_hire_holder');
    $probe = sesionDelRenombradoConAlta('rename_during_hire_probe');

    $cadena->beginTransaction();
    AuditChainLock::takeOn($cadena);

    $renombrado = UseCaseInOtherProcess::of('rename_department', [
        'department' => (string) $departamento,
        'name' => 'Pisos y lavanderia',
    ], DEPARTMENT_RENAME_DURING_HIRE_NOW)->start();
    $renombradoEspera = esperarSesionesBloqueadas($probe, 1, $renombrado);

    $alta = UseCaseInOtherProcess::of('register_employee_in_transaction', [
        'department' => (string) $departamento,
    ], DEPARTMENT_RENAME_DURING_HIRE_NOW)->start();
    $altaEspera = esperarSesionesBloqueadas($probe, 2, $alta);

    $cadena->rollBack();

    expect([$renombradoEspera, $altaEspera])->toBe([true, true])
        ->and([$renombrado->finish(), $alta->finish()])->toBe([UseCaseInOtherProcess::COMMITTED, UseCaseInOtherProcess::COMMITTED])
        ->and(DB::table('departments')->where('id', $departamento)->value('name'))->toBe('Pisos y lavanderia')
        ->and(DB::table('employees')->where('department_id', $departamento)->count())->toBe(1);
})->group('RF-GP-01', 'RF-ID-10', 'RL-04');
