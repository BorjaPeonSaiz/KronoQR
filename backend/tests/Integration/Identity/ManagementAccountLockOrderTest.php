<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Support\ManagementAccountRosterLock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Persistence\AuditChainLock;
use App\Modules\Workforce\Application\Exception\DepartmentManagerNotEligible;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Concurrency\UseCaseInOtherProcess;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL ORDEN DE CANDADOS DE LAS CUENTAS DE GESTION** (RF-ID-10, ADR-051 §6,
 * RS-06, RL-04).
 *
 * El orden unico es: fila del departamento → cadena de `audit_log` → padron de
 * cuentas (`ManagementAccountRosterLock`) → fila de `users`. Dos cosas se miran
 * aqui por fuera, con una sesion que toma la cadena y un caso de uso en otro
 * proceso que se queda esperandola (se ve en `pg_locks`):
 *
 *   1. **Sin abrazo**: mientras espera la cadena, la baja y los dos
 *      restablecimientos no tienen ni el padron ni la fila de la cuenta. Si los
 *      tuvieran, cualquiera que vaya en el orden correcto cerraria el ciclo con
 *      ellos y uno moriria con `40P01`.
 *   2. **Una baja en vuelo gana a la asignacion de responsable**: la asignacion
 *      lee la cuenta DESPUES de tener la cadena, asi que ve la baja confirmada
 *      y la rechaza; nunca asigna una cuenta que se esta dando de baja y deja
 *      su asiento detras del de la baja.
 *
 * Es la version determinista de lo que `ManagementAccountsConcurrencyTest`
 * (Feature) busca con procesos en carrera: por HTTP, la asignacion es tan
 * rapida que la baja casi nunca la pilla a medias (medido el 07-10-2026: seis
 * parejas sin candado, cero carreras en tres pasadas).
 */

uses(CommittedDatabase::class);

const MANAGEMENT_LOCK_ORDER_NOW = '2026-10-07 10:00:00';

beforeEach(function (): void {
    FrozenTime::at(MANAGEMENT_LOCK_ORDER_NOW);
});

afterEach(function (): void {
    DB::purge('management_lock_order_holder');
    DB::purge('management_lock_order_probe');
    ChildSessions::waitUntilGone();
});

function sesionDeCuentasEnOrden(string $nombre): ConnectionInterface
{
    config(['database.connections.'.$nombre => config()->array('database.connections.pgsql')]);

    return DB::connection($nombre);
}

/**
 * Espera, **por condicion**, a que el otro proceso este esperando la cadena. El
 * tope existe para que un caso de uso que termine sin pedirla se vea como tal.
 */
function esperarCuentasEnCadena(ConnectionInterface $probe, UseCaseInOtherProcess $casoDeUso): bool
{
    for ($attempt = 0; $attempt < 10_000; $attempt++) {
        /** @var object{waiting: int} $row */
        $row = $probe->selectOne(
            'SELECT count(*) AS waiting FROM pg_locks WHERE locktype = ? AND NOT granted AND classid = ? AND objid = ?',
            ['advisory', AuditChainLock::LOCK_NAMESPACE, AuditChainLock::LOCK_RESOURCE],
        );

        if ($row->waiting > 0) {
            return true;
        }

        if (! $casoDeUso->isRunning()) {
            return false;
        }

        usleep(2_000);
    }

    return false;
}

/**
 * `ok` si la sonda toma la fila de la cuenta sin esperar, o el SQLSTATE con el
 * que la base se lo niega.
 */
function filaDeCuentaLibre(ConnectionInterface $probe, int $userId): string
{
    try {
        $probe->select('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE NOWAIT', [$userId]);

        return 'ok';
    } catch (QueryException $failure) {
        return (string) $failure->getCode();
    }
}

function padronDeCuentasLibre(ConnectionInterface $probe): bool
{
    $probe->beginTransaction();

    try {
        /** @var object{taken: bool} $row */
        $row = $probe->selectOne('SELECT pg_try_advisory_xact_lock(?) AS taken', [ManagementAccountRosterLock::KEY]);

        return $row->taken;
    } finally {
        $probe->rollBack();
    }
}

it('espera la cadena sin tener el padron ni la fila de la cuenta', function (string $casoDeUso): void {
    ManagementUsers::withRole(UserRole::ADMIN);
    $cuenta = ManagementUsers::withRole(UserRole::ADMIN);
    ManagementUsers::withActiveSecondFactor($cuenta);
    $cadena = sesionDeCuentasEnOrden('management_lock_order_holder');
    $probe = sesionDeCuentasEnOrden('management_lock_order_probe');

    $cadena->beginTransaction();
    AuditChainLock::takeOn($cadena);
    $otroProceso = UseCaseInOtherProcess::of($casoDeUso, ['account' => $cuenta->uuid], MANAGEMENT_LOCK_ORDER_NOW)->start();

    $esperaba = esperarCuentasEnCadena($probe, $otroProceso);
    $fila = filaDeCuentaLibre($probe, $cuenta->id);
    $padron = padronDeCuentasLibre($probe);

    $cadena->rollBack();

    expect([$esperaba, $fila, $padron])->toBe([true, 'ok', true])
        ->and($otroProceso->finish())->toBe(UseCaseInOtherProcess::COMMITTED);
})->with([
    'baja' => ['deactivate_management_account'],
    'restablecimiento de la contrasena' => ['reset_management_password'],
    'restablecimiento del segundo factor' => ['reset_management_two_factor'],
])->group('RF-ID-10', 'RS-06', 'RL-04');

it('rechaza asignar como responsable a una cuenta cuya baja esta en vuelo', function (): void {
    $departamento = WorkforceFixtures::department(WorkforceFixtures::site());
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $baja = sesionDeCuentasEnOrden('management_lock_order_holder');
    $probe = sesionDeCuentasEnOrden('management_lock_order_probe');

    // La baja en vuelo, como la hace `DeactivateManagementAccountHandler`:
    // cadena, padron y fila, sin confirmar todavia.
    $baja->beginTransaction();
    AuditChainLock::takeOn($baja);
    ManagementAccountRosterLock::acquire($baja);
    $baja->update('UPDATE users SET is_active = false WHERE id = ?', [$responsable->id]);

    $asignacion = UseCaseInOtherProcess::of('assign_department_manager', [
        'department' => (string) $departamento,
        'manager' => $responsable->uuid,
    ], MANAGEMENT_LOCK_ORDER_NOW)->start();

    $esperaba = esperarCuentasEnCadena($probe, $asignacion);

    $baja->commit();

    expect($esperaba)->toBeTrue()
        ->and($asignacion->finish())->toBe('rejected:'.DepartmentManagerNotEligible::class)
        ->and(DB::table('departments')->where('id', $departamento)->value('manager_user_id'))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'role_assignment.changed')->count())->toBe(0);
})->group('RF-ID-10', 'RF-ID-03', 'RL-04');

it('el control: con la cadena tomada pero sin baja, la asignacion espera y se confirma', function (): void {
    // Demuestra que la prueba de arriba no rechaza por el montaje: la misma
    // espera, sin tocar la cuenta, termina con el responsable asignado.
    $departamento = WorkforceFixtures::department(WorkforceFixtures::site());
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cadena = sesionDeCuentasEnOrden('management_lock_order_holder');
    $probe = sesionDeCuentasEnOrden('management_lock_order_probe');

    $cadena->beginTransaction();
    AuditChainLock::takeOn($cadena);

    $asignacion = UseCaseInOtherProcess::of('assign_department_manager', [
        'department' => (string) $departamento,
        'manager' => $responsable->uuid,
    ], MANAGEMENT_LOCK_ORDER_NOW)->start();

    $esperaba = esperarCuentasEnCadena($probe, $asignacion);

    $cadena->rollBack();

    expect($esperaba)->toBeTrue()
        ->and($asignacion->finish())->toBe(UseCaseInOtherProcess::COMMITTED)
        ->and(DB::table('departments')->where('id', $departamento)->value('manager_user_id'))->toBe($responsable->id);
})->group('RF-ID-10', 'RF-ID-03', 'RL-04');
