<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **ESCRIBIR LA FICHA NO HACE ESPERAR A LOS FICHAJES DE ESA PERSONA**
 * (ADR-046 §2 y §6 punto 2; reglas duras 5 y 19).
 *
 * La ficha se toma con `FOR NO KEY UPDATE`, que serializa a quienes la escriben
 * y **no choca** con el `FOR KEY SHARE` con el que cada fila hija comprueba su
 * clave ajena. Con `FOR UPDATE` —o con un `UPDATE` que tocara `id`, `uuid` o
 * `employee_code`— un fichaje, una ausencia o una tarjeta de esa persona se
 * quedarian esperando a la baja.
 *
 * Dos sesiones y no dos procesos: la de la prueba toma la ficha dentro de una
 * transaccion abierta y la segunda, con `lock_timeout` corto, intenta lo que
 * hace el resto del producto. **Sin medir tiempos**: si la segunda tuviera que
 * esperar, su `lock_timeout` la cortaria con `55P03` y la prueba lo diria.
 *
 * `CommittedDatabase`: la segunda sesion no veria las filas de la transaccion
 * envolvente de `RefreshDatabase`.
 */

uses(CommittedDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
});

afterEach(function (): void {
    DB::purge('employee_lock_probe');
    // Bloque 17: ninguna sesion de otro proceso sobrevive a la prueba; si
    // quedara alguna, llenaria `max_connections` para la siguiente.
    ChildSessions::waitUntilGone();
});

/**
 * La otra sesion, con un `lock_timeout` que convierte cualquier espera en error.
 */
function otraSesionDeLaFicha(): ConnectionInterface
{
    config(['database.connections.employee_lock_probe' => config()->array('database.connections.pgsql')]);
    $probe = DB::connection('employee_lock_probe');
    $probe->statement("SET lock_timeout = '300ms'");

    return $probe;
}

/**
 * Una persona con una tarjeta impresa y un quiosco del centro.
 *
 * @return array{uuid: string, id: int, device: int}
 */
function fichaParaElCandado(): array
{
    $site = WorkforceFixtures::site('Hotel del candado');
    $uuid = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos'));
    $id = AttendanceFixtures::employeeIdOf($uuid);
    Credentials::issueFor($id);

    return ['uuid' => $uuid, 'id' => $id, 'device' => AttendanceFixtures::device($site)['id']];
}

/**
 * Deja la ficha como la deja el caso de uso antes de confirmar.
 */
function tomarLaFichaComoElCasoDeUso(string $modo, string $uuid): void
{
    $repositorio = app(EmployeeRepository::class);
    $ficha = $repositorio->findForUpdate($uuid);

    expect($ficha)->toBeInstanceOf(Employee::class);

    /** @var Employee $ficha */
    match ($modo) {
        'leida' => null,
        // El candado que ADR-046 prohibe sobre esta tabla, como control.
        'FOR UPDATE' => DB::select('SELECT id FROM employees WHERE uuid = ? FOR UPDATE', [$uuid]),
        'modificada' => $repositorio->saveProfile($ficha->updateProfile(lastName: 'Apellido Nuevo'), statusChanged: false),
        'dada de baja' => $repositorio->saveTermination(
            $ficha->offboard(new DateTimeImmutable('2026-10-02'), new DateTimeImmutable('2026-10-02')),
        ),
        default => throw new RuntimeException('Modo desconocido: '.$modo),
    };
}

/**
 * Ejecuta la sentencia en la otra sesion y devuelve `ok` o el SQLSTATE con el
 * que la corto la base.
 */
function sqlstateEnLaOtraSesion(callable $sentencia): string
{
    try {
        $sentencia();

        return 'ok';
    } catch (QueryException $failure) {
        return (string) $failure->getCode();
    }
}

/**
 * Inserta la fila en la otra sesion, dentro de su propia transaccion, y la
 * revierte: lo que se mira es si tuvo que esperar, no la fila.
 *
 * @param  array<string, mixed>  $fila
 */
function insertarYRevertirEnLaOtraSesion(ConnectionInterface $probe, string $tabla, array $fila): string
{
    $probe->beginTransaction();

    try {
        return sqlstateEnLaOtraSesion(static fn (): bool => $probe->table($tabla)->insert($fila));
    } finally {
        $probe->rollBack();
    }
}

/**
 * Lo que el resto del producto inserta colgando de la ficha, en la otra sesion
 * y revertido al terminar: un escaneo, una ausencia y una tarjeta.
 *
 * @param  array{uuid: string, id: int, device: int}  $ficha
 * @return array{scan_events: string, absences: string, credentials: string}
 */
function insertarFilasHijasEnLaOtraSesion(ConnectionInterface $probe, array $ficha): array
{
    return [
        'scan_events' => insertarYRevertirEnLaOtraSesion($probe, 'scan_events', [
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $ficha['device'],
            'employee_id' => $ficha['id'],
            'occurred_at' => '2026-10-02 09:59:00+00',
            'recorded_at' => '2026-10-02 10:00:00+00',
            'origin' => 'qr_kiosk',
            'intent' => 'auto',
            'result' => 'clock_in',
            'shift_entry_id' => null,
            'worked_minutes' => 0,
            'client_meta' => '{}',
            'flagged_for_review' => false,
        ]),
        'absences' => insertarYRevertirEnLaOtraSesion($probe, 'absences', [
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $ficha['id'],
            'type' => 'vacation',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-18',
            'status' => 'active',
            'version' => 1,
            'created_at' => '2026-10-02 10:00:00+00',
        ]),
        'credentials' => insertarYRevertirEnLaOtraSesion($probe, 'credentials', [
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $ficha['id'],
            'issued_at' => '2026-10-02 10:00:00+00',
        ]),
    ];
}

it('con la ficha tomada por quien la escribe, otra sesion inserta escaneos, ausencias y tarjetas sin esperar', function (string $modo): void {
    $ficha = fichaParaElCandado();
    $probe = otraSesionDeLaFicha();

    $insertadas = DB::connection()->transaction(static function () use ($modo, $ficha, $probe): array {
        tomarLaFichaComoElCasoDeUso($modo, $ficha['uuid']);

        return insertarFilasHijasEnLaOtraSesion($probe, $ficha);
    });

    expect($insertadas)->toBe(['scan_events' => 'ok', 'absences' => 'ok', 'credentials' => 'ok']);
})->with([
    'leida con findForUpdate' => ['leida'],
    'tras saveProfile sin confirmar' => ['modificada'],
    'tras saveTermination sin confirmar' => ['dada de baja'],
])->group('RN-14', 'RF-GP-01', 'RF-GP-03', 'RF-AT-07');

it('con la ficha tomada por quien la escribe, otra escritura de la ficha no puede tomarla', function (string $modo): void {
    $ficha = fichaParaElCandado();
    $probe = otraSesionDeLaFicha();

    $sqlstate = DB::connection()->transaction(static function () use ($modo, $ficha, $probe): string {
        tomarLaFichaComoElCasoDeUso($modo, $ficha['uuid']);

        return sqlstateEnLaOtraSesion(static fn (): array => $probe->select(
            'SELECT id FROM employees WHERE id = ? FOR NO KEY UPDATE NOWAIT',
            [$ficha['id']],
        ));
    });

    expect($sqlstate)->toBe('55P03');
})->with([
    'leida con findForUpdate' => ['leida'],
    'tras saveProfile sin confirmar' => ['modificada'],
    'tras saveTermination sin confirmar' => ['dada de baja'],
])->group('RN-14', 'RF-GP-01', 'RF-GP-03');

it('el control: con FOR UPDATE sobre la ficha, las tres inserciones tendrian que esperar', function (): void {
    // Demuestra que la prueba de arriba puede fallar: es el candado que el
    // ADR-046 descarta, y la otra sesion se queda esperando hasta su `lock_timeout`.
    $ficha = fichaParaElCandado();
    $probe = otraSesionDeLaFicha();

    $insertadas = DB::connection()->transaction(static function () use ($ficha, $probe): array {
        tomarLaFichaComoElCasoDeUso('FOR UPDATE', $ficha['uuid']);

        return insertarFilasHijasEnLaOtraSesion($probe, $ficha);
    });

    expect($insertadas)->toBe(['scan_events' => '55P03', 'absences' => '55P03', 'credentials' => '55P03']);
})->group('RN-14', 'RF-AT-07');

it('al confirmar, la ficha vuelve a estar libre', function (): void {
    $ficha = fichaParaElCandado();
    $probe = otraSesionDeLaFicha();

    DB::connection()->transaction(static function () use ($ficha): void {
        tomarLaFichaComoElCasoDeUso('modificada', $ficha['uuid']);
    });

    expect(sqlstateEnLaOtraSesion(static fn (): array => $probe->select(
        'SELECT id FROM employees WHERE id = ? FOR NO KEY UPDATE NOWAIT',
        [$ficha['id']],
    )))->toBe('ok');
})->group('RN-14', 'RF-GP-01');
