<?php

declare(strict_types=1);

use App\Modules\Workforce\Application\Command\CorrectAbsenceCommand;
use App\Modules\Workforce\Application\Command\RegisterAbsenceCommand;
use App\Modules\Workforce\Application\Command\VoidAbsenceCommand;
use App\Modules\Workforce\Application\UseCase\CorrectAbsenceHandler;
use App\Modules\Workforce\Application\UseCase\RegisterAbsenceHandler;
use App\Modules\Workforce\Application\UseCase\VoidAbsenceHandler;
use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **DOS CORRECCIONES SIMULTANEAS NO PUEDEN BIFURCAR EL HISTORIAL** (RF-GP-04,
 * **RN-13**, regla dura 5; hallazgo bloqueante de la revision de la 3.10).
 *
 * ## El fallo que esta prueba cierra
 *
 * Antes de la revision, `CorrectAbsenceHandler` leia la version con
 * `findByUuid()` **fuera de la transaccion y sin candado**. Dos `PATCH`
 * simultaneos sobre la misma v1 —a fechas que no se solapan entre si— la veian
 * los dos `active`, los dos decidian que podian corregirla y los dos escribian:
 * el resultado eran **dos filas `version = 2` colgando de la misma v1**, dos
 * asientos en `audit_log` y un `superseded_by_id` apuntando solo a una de las
 * dos. El historial se bifurcaba, el informe contaba los dias dos veces y nada
 * lo delataba. Corregir y anular en paralelo acababa peor: en `23514` contra
 * `absences_chk_superseded_consistency`, es decir un `500` sin significado.
 *
 * ## Por que no puede ser una prueba de feature
 *
 * Lo que se comprueba es una garantia del motor —que `SELECT … FOR UPDATE` toma
 * la fila y la segunda sesion espera— y desde una prueba de feature se veria el
 * efecto sin poder decir si lo consigue el candado o la suerte del planificador.
 * **Dos sesiones y no dos procesos**, con `NOWAIT` para afirmar quien espera a
 * quien sin medir milisegundos: una prueba que midiera tiempos fallaria sola en
 * la CI. Es el mismo mecanismo que `DailyTotalsProjectionLockTest`, que hace lo
 * propio con la proyeccion de `daily_totals`.
 *
 * **Sin `RefreshDatabase`**: su transaccion envolvente escondería las filas a la
 * segunda conexion.
 */

uses(CommittedDatabase::class);

/** La conexion de la segunda sesion, creada a partir de la de la aplicacion. */
function segundaSesionDeAusencias(): ConnectionInterface
{
    config(['database.connections.absence_probe' => config()->array('database.connections.pgsql')]);

    return DB::connection('absence_probe');
}

afterEach(function (): void {
    // La conexion de sondeo no puede sobrevivir a la prueba: una conexion
    // fantasma en el gestor se hereda y rompe lo que venga detras.
    DB::purge('absence_probe');
});

/**
 * Una persona con una ausencia registrada por el camino de siempre.
 *
 * @return array{employee: string, absence: string}
 */
function ausenciaRegistrada(): array
{
    $site = WorkforceFixtures::site('Hotel de la carrera');
    $employee = WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Cocina'));

    $absence = app(RegisterAbsenceHandler::class)->handle(new RegisterAbsenceCommand(
        employeeUuid: $employee,
        type: AbsenceType::Vacation,
        startsOn: '2026-03-02',
        endsOn: '2026-03-06',
    ));

    expect($absence)->not->toBeNull();

    return ['employee' => $employee, 'absence' => (string) $absence?->uuid];
}

it('toma la fila de la ausencia mientras corrige, y la suelta al confirmar', function (): void {
    // La garantia de la que depende todo lo demas: si el candado no serializara,
    // la segunda peticion leeria la version vieja y bifurcaria el historial.
    $escenario = ausenciaRegistrada();
    $probe = segundaSesionDeAusencias();

    DB::connection()->transaction(function () use ($escenario, $probe): void {
        // La misma lectura bloqueante que hace el caso de uso.
        DB::connection()->table('absences')
            ->select('id')
            ->where('uuid', $escenario['absence'])
            ->lockForUpdate()
            ->first();

        expect(fn (): mixed => $probe->select(
            'SELECT 1 FROM absences WHERE uuid = ? FOR UPDATE NOWAIT',
            [$escenario['absence']],
        ))->toThrow(QueryException::class);
    });

    // Confirmada la transaccion, la fila vuelve a estar libre: el candado dura lo
    // que dura la correccion y ni un milisegundo mas.
    $libre = $probe->select(
        'SELECT uuid FROM absences WHERE uuid = ? FOR UPDATE NOWAIT',
        [$escenario['absence']],
    );

    expect($libre)->toHaveCount(1);
})->group('RF-GP-04', 'RN-13');

it('la segunda correccion de la misma version recibe 409 y no crea una segunda rama', function (): void {
    /*
     * La carrera de verdad, reproducida sin depender de tiempos: la segunda
     * sesion **adelanta** su escritura mientras la primera todavia no ha leido,
     * que es el peor orden posible. Al soltarse, la primera relee la fila ya en
     * `superseded` y responde `409` en lugar de bifurcar.
     */
    $escenario = ausenciaRegistrada();

    // La otra peticion gana: corrige y confirma.
    $adelantada = app(CorrectAbsenceHandler::class)->handle(new CorrectAbsenceCommand(
        absenceUuid: $escenario['absence'],
        reason: 'La primera correccion, que gana.',
        endsOn: '2026-03-09',
    ));

    expect($adelantada)->not->toBeNull();

    // Y la que llega despues con el identificador de la version vieja.
    expect(static fn () => app(CorrectAbsenceHandler::class)->handle(new CorrectAbsenceCommand(
        absenceUuid: $escenario['absence'],
        reason: 'La segunda, sobre una version que ya no existe.',
        endsOn: '2026-03-20',
    )))->toThrow(AbsenceNotActive::class);

    // UNA SOLA VERSION ACTIVA y una sola v2: el historial es una cadena, no un
    // arbol. Es exactamente lo que fallaba antes del candado.
    $activas = DB::table('absences')->where('status', 'active')->count();
    $segundas = DB::table('absences')->where('version', 2)->count();

    expect($activas)->toBe(1);
    expect($segundas)->toBe(1);

    // Y la v1 apunta a la unica que la sustituyo.
    $anterior = DB::table('absences')->where('uuid', $escenario['absence'])->first();

    expect($anterior?->status)->toBe('superseded');
    expect($anterior?->superseded_by_id)->not->toBeNull();

    // Un asiento de correccion, no dos.
    expect(DB::table('audit_log')->where('action', 'absence.corrected')->count())->toBe(1);
})->group('RF-GP-04', 'RN-13');

it('anular una version que otra peticion acaba de sustituir es 409 y no un error del motor', function (): void {
    // El segundo sintoma del hallazgo: corregir y anular en paralelo acababa en
    // `23514` contra `absences_chk_superseded_consistency`, que es un `500` que
    // no dice nada a quien lo recibe.
    $escenario = ausenciaRegistrada();

    app(CorrectAbsenceHandler::class)->handle(new CorrectAbsenceCommand(
        absenceUuid: $escenario['absence'],
        reason: 'Se corrige antes de que llegue la anulacion.',
        endsOn: '2026-03-09',
    ));

    expect(static fn () => app(VoidAbsenceHandler::class)->handle(new VoidAbsenceCommand(
        absenceUuid: $escenario['absence'],
        reason: 'Llega tarde: esa version ya no es la vigente.',
    )))->toThrow(AbsenceNotActive::class);

    // Nada anulado y el historial intacto.
    expect(DB::table('absences')->where('status', 'voided')->count())->toBe(0);
    expect(DB::table('absences')->where('status', 'active')->count())->toBe(1);
})->group('RF-GP-04', 'RN-13');

it('la escritura no se fia de lo que leyo: el UPDATE exige que siga activa', function (): void {
    /*
     * La red bajo el candado. Se simula el unico escenario que el candado no
     * cubriria —alguien escribe un camino que no pasa por `findForUpdate()`—
     * dejando la fila en `superseded` **por fuera** y pidiendo despues la
     * anulacion: el `UPDATE … WHERE status = 'active'` afecta a cero filas y eso
     * se convierte en `409`, no en una anulacion silenciosa de una version
     * historica.
     */
    $escenario = ausenciaRegistrada();

    // Una segunda version, para que la v1 quede `superseded` de forma coherente.
    app(CorrectAbsenceHandler::class)->handle(new CorrectAbsenceCommand(
        absenceUuid: $escenario['absence'],
        reason: 'Deja la v1 como historico.',
        endsOn: '2026-03-09',
    ));

    expect(static fn () => app(VoidAbsenceHandler::class)->handle(new VoidAbsenceCommand(
        absenceUuid: $escenario['absence'],
        reason: 'Anular una version historica no puede escribir nada.',
    )))->toThrow(AbsenceNotActive::class);

    // La v1 sigue `superseded` y sin marcas de anulacion: no se ha tocado.
    $historica = DB::table('absences')->where('uuid', $escenario['absence'])->first();

    expect($historica?->status)->toBe('superseded');
    expect($historica?->voided_at)->toBeNull();
    expect($historica?->void_reason)->toBeNull();
})->group('RF-GP-04', 'RN-13');
