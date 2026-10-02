<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Persistence\AuditChainLock;
use App\Modules\Workforce\Application\UseCase\PlanEmployeeImport;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Concurrency\UseCaseInOtherProcess;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **QUIEN ESPERA LA CADENA NO TIENE LA FICHA NI LA TARJETA**
 * (ADR-046 §1 y §6 punto 3; condicion A-1 del dictamen de seguridad).
 *
 * El orden unico es: filas padre → cadena de `audit_log` → `employees` →
 * `credentials`. Si un caso de uso tomara la ficha o la tarjeta y despues
 * esperase la cadena, cualquier otro que vaya en el orden correcto —la baja,
 * por ejemplo— cerraria el ciclo con el y uno de los dos moriria con `40P01`.
 *
 * La prueba lo mira por fuera: una sesion propia toma la cadena; el caso de uso
 * arranca en otro proceso y se queda esperandola (se ve en `pg_locks`); y en ese
 * momento una tercera sesion toma con `NOWAIT` la ficha y todas las tarjetas de
 * esa persona. Si el caso de uso las tuviera, `NOWAIT` responderia `55P03`.
 * Despues se suelta la cadena y el caso de uso tiene que terminar bien.
 *
 * Cubre los casos de uso de la tabla §1.2 que toman la cadena: los de la ficha
 * (modificacion, cambio de departamento, baja, importacion), los tres del PIN
 * (la emision va dentro del restablecimiento) y los cinco de `Identity`.
 */

uses(CommittedDatabase::class);

const EMPLOYEE_LOCK_ORDER_NOW = '2026-10-02 10:00:00';

beforeEach(function (): void {
    FrozenTime::at(EMPLOYEE_LOCK_ORDER_NOW);
});

afterEach(function (): void {
    DB::purge('lock_order_chain_holder');
    DB::purge('lock_order_probe');
    // Bloque 17: ninguna sesion de otro proceso sobrevive a la prueba; si
    // quedara alguna, llenaria `max_connections` para la siguiente.
    ChildSessions::waitUntilGone();
});

function sesionDelOrdenDeCandados(string $nombre): ConnectionInterface
{
    config(['database.connections.'.$nombre => config()->array('database.connections.pgsql')]);

    return DB::connection($nombre);
}

/**
 * Cuantas sesiones esperan ahora mismo el candado consultivo de la cadena.
 */
function sesionesEsperandoLaCadena(ConnectionInterface $probe): int
{
    /** @var object{waiting: int} $row */
    $row = $probe->selectOne(
        'SELECT count(*) AS waiting FROM pg_locks WHERE locktype = ? AND NOT granted AND classid = ? AND objid = ?',
        ['advisory', AuditChainLock::LOCK_NAMESPACE, AuditChainLock::LOCK_RESOURCE],
    );

    return $row->waiting;
}

/**
 * Espera, **por condicion**, a que el otro proceso este esperando la cadena. El
 * tope existe para que un hijo que nunca llegue no deje la prueba girando, y
 * para que un caso de uso que termine sin pedir la cadena se vea como tal.
 */
function esperarAQueEsperenLaCadena(ConnectionInterface $probe, UseCaseInOtherProcess $casoDeUso): bool
{
    for ($attempt = 0; $attempt < 10_000; $attempt++) {
        if (sesionesEsperandoLaCadena($probe) > 0) {
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
 * `ok` si la otra sesion toma la fila sin esperar, o el SQLSTATE con el que la
 * base se lo niega.
 *
 * @param  list<int|string>  $bindings
 */
function tomarSinEsperar(ConnectionInterface $probe, string $sql, array $bindings): string
{
    try {
        $probe->select($sql, $bindings);

        return 'ok';
    } catch (QueryException $failure) {
        return (string) $failure->getCode();
    }
}

/**
 * Una persona con una tarjeta impresa (y sin entregar) firmada con la clave que
 * se indique, y lo que cada caso de uso necesita para ejecutarse de verdad.
 *
 * @return array{employee: int, arguments: array<string, string>}
 */
function escenarioDelOrdenDeCandados(string $casoDeUso): array
{
    $site = WorkforceFixtures::site('Hotel del orden de candados');
    $pisos = WorkforceFixtures::department($site, 'Pisos');
    $uuid = WorkforceFixtures::employee($site, $pisos);
    $id = AttendanceFixtures::employeeIdOf($uuid);
    DB::table('employees')->where('id', $id)->update(['email' => 'orden.candados@example.test']);
    EmployeePins::issue($uuid, '374195');

    // La rotacion solo reemite las tarjetas firmadas con la clave saliente.
    Credentials::issueFor($id, $casoDeUso === 'rotate_signing_key' ? Credentials::previousKey() : null);
    /** @var string $tarjeta */
    $tarjeta = DB::table('credentials')->where('employee_id', $id)->value('uuid');

    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    // Cada entrada prepara lo suyo solo si es la que se pide: son clausuras.
    /** @var array<string, callable(): array<string, string>> $argumentos */
    $argumentos = [
        'update_last_name' => static fn (): array => ['employee' => $uuid, 'last_name' => 'Apellido Nuevo'],
        'move_department' => static fn (): array => ['employee' => $uuid, 'department' => (string) WorkforceFixtures::department($site, 'Cocina')],
        'offboard' => static fn (): array => ['employee' => $uuid, 'terminated_on' => '2026-10-02'],
        'import' => argumentosDeLaImportacionDelOrden(...),
        'reset_pin' => static fn (): array => ['employee' => $uuid],
        'deliver_pin' => static fn (): array => ['employee' => $uuid, 'user' => $admin->uuid],
        'issue_credential' => static fn (): array => ['employee' => $uuid] + revocarTarjetaDelOrden($tarjeta),
        'rotate_signing_key' => static fn (): array => [],
        'revoke_credential' => static fn (): array => ['credential' => $tarjeta],
        'print_credential' => static fn (): array => ['credential' => Credentials::pendingFor($id)] + revocarTarjetaDelOrden($tarjeta),
        'deliver_credential' => static fn (): array => ['credential' => $tarjeta, 'user' => (string) $admin->id],
    ];

    return ['employee' => $id, 'arguments' => $argumentos[$casoDeUso]()];
}

/**
 * La emision y la impresion solo se ejecutan para quien no tiene ninguna
 * tarjeta en la mano.
 *
 * @return array{}
 */
function revocarTarjetaDelOrden(string $tarjeta): array
{
    DB::table('credentials')->where('uuid', $tarjeta)->update([
        'revoked_at' => '2026-09-30 10:00:00+00',
        'revoked_reason' => 'Perdida',
    ]);

    return [];
}

/**
 * Un fichero con una linea que modifica a la persona (casa por el correo) y su
 * huella, comprobado antes como lo haria el panel.
 *
 * @return array{path: string, checksum: string}
 */
function argumentosDeLaImportacionDelOrden(): array
{
    $path = (string) ImportFiles::csv(ImportFiles::rows(
        ['nombre', 'apellidos', 'email', 'fecha_alta'],
        [['Persona', 'Apellido Importado', 'orden.candados@example.test', '2026-01-01']],
    ))->getRealPath();

    /** @var array<string, list<string>> $aliases */
    $aliases = config()->array('workforce.import.column_aliases');

    return ['path' => $path, 'checksum' => app(PlanEmployeeImport::class)->handle($path, 500, $aliases)->sha256];
}

it('espera la cadena sin tener la ficha ni las tarjetas de la persona', function (string $casoDeUso): void {
    $escenario = escenarioDelOrdenDeCandados($casoDeUso);
    $cadena = sesionDelOrdenDeCandados('lock_order_chain_holder');
    $probe = sesionDelOrdenDeCandados('lock_order_probe');

    $cadena->beginTransaction();
    AuditChainLock::takeOn($cadena);
    $otroProceso = UseCaseInOtherProcess::of($casoDeUso, $escenario['arguments'], EMPLOYEE_LOCK_ORDER_NOW)->start();

    $esperaba = esperarAQueEsperenLaCadena($probe, $otroProceso);
    $ficha = tomarSinEsperar($probe, 'SELECT id FROM employees WHERE id = ? FOR NO KEY UPDATE NOWAIT', [$escenario['employee']]);
    $tarjetas = tomarSinEsperar($probe, 'SELECT id FROM credentials WHERE employee_id = ? FOR NO KEY UPDATE NOWAIT', [$escenario['employee']]);

    $cadena->rollBack();

    expect([$esperaba, $ficha, $tarjetas])->toBe([true, 'ok', 'ok'])
        ->and($otroProceso->finish())->toBe(UseCaseInOtherProcess::COMMITTED);
})->with([
    'modificacion de la ficha' => ['update_last_name'],
    'cambio de departamento' => ['move_department'],
    'baja' => ['offboard'],
    'importacion que modifica' => ['import'],
    'restablecimiento (y emision) del PIN' => ['reset_pin'],
    'entrega del PIN' => ['deliver_pin'],
    'emision de tarjeta' => ['issue_credential'],
    'rotacion de la clave de firma' => ['rotate_signing_key'],
    'revocacion manual de tarjeta' => ['revoke_credential'],
    'entrega de tarjeta' => ['deliver_credential'],
    'impresion de tarjeta' => ['print_credential'],
])->group('RN-14', 'RF-GP-01', 'RF-GP-03', 'RF-GP-05', 'RF-ID-09', 'RF-QR-03', 'RF-QR-04', 'RF-QR-06', 'RF-QR-07', 'RL-04');

it('el control: quien toma la ficha antes que la cadena se ve desde fuera', function (): void {
    // Demuestra que la prueba de arriba puede fallar. El otro proceso sigue el
    // orden prohibido —ficha y despues cadena— y la tercera sesion lo ve: la
    // ficha esta tomada mientras espera.
    $escenario = escenarioDelOrdenDeCandados('update_last_name');
    $cadena = sesionDelOrdenDeCandados('lock_order_chain_holder');
    $probe = sesionDelOrdenDeCandados('lock_order_probe');

    $cadena->beginTransaction();
    AuditChainLock::takeOn($cadena);
    $otroProceso = UseCaseInOtherProcess::of('control_row_then_chain', $escenario['arguments'], EMPLOYEE_LOCK_ORDER_NOW)->start();

    $esperaba = esperarAQueEsperenLaCadena($probe, $otroProceso);
    $ficha = tomarSinEsperar($probe, 'SELECT id FROM employees WHERE id = ? FOR NO KEY UPDATE NOWAIT', [$escenario['employee']]);

    $cadena->rollBack();

    expect([$esperaba, $ficha])->toBe([true, '55P03'])
        ->and($otroProceso->finish())->toBe(UseCaseInOtherProcess::COMMITTED);
})->group('RN-14', 'RL-04');
