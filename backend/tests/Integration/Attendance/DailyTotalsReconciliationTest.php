<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\CorrectShiftCommand;
use App\Modules\Attendance\Application\Command\VoidShiftCommand;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Application\UseCase\VoidShiftHandler;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La reconciliacion de `daily_totals` con sus eventos origen contra PostgreSQL
 * de verdad (RF-PR-02, RN-06, ADR-007, tarea 2.7).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Lo que se comprueba aqui es
 * precisamente la relacion entre dos tablas —la fuente de verdad y su
 * proyeccion— y el efecto de escribir en una de ellas por fuera de la
 * aplicacion, que es el supuesto entero de la tarea. Un doble en memoria no
 * puede corromper una fila «por detras»: seria el mismo codigo fingiendo que se
 * equivoca.
 *
 * EL RELOJ ESTA FIJO (regla dura 2). Sin el, «reconcilia el turno de noche del
 * 14 de marzo» dependeria del dia en que se ejecute la suite, y el rango por
 * defecto —ayer— no seria comprobable.
 *
 * LAS FECHAS SON LAS DE LOS CASOS LIMITE de la semilla de desarrollo (doc 02
 * §10.2): turno nocturno el 14 de marzo, cambio de hora de primavera el 28 de
 * marzo y de otoño el 24 de octubre. Estan elegidas para que lo que aqui se
 * afirma se pueda repetir a mano sobre la semilla.
 */

uses(RefreshDatabase::class);

/** El «ahora» de todas las pruebas de este fichero. */
const RECONCILIATION_NOW = '2026-11-02 03:50:00';

/**
 * Centro en Madrid y un empleado dentro.
 *
 * @return array{site: int, employee: string}
 */
function reconciliationScenario(): array
{
    $site = WorkforceFixtures::site('Hotel de reconciliacion', 'Europe/Madrid');

    return [
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site, WorkforceFixtures::department($site, 'Pisos')),
    ];
}

/**
 * Un tramo escrito directamente en la tabla, con sus marcas ya en UTC.
 *
 * Sin pasar por el caso de uso a proposito: estas pruebas necesitan estados que
 * el fichaje tardaria una noche entera en producir, y una jornada de octubre no
 * se puede fichar en marzo.
 */
function reconciledShiftEntry(
    string $employeeUuid,
    int $siteId,
    string $workDate,
    string $clockedInAt,
    ?string $clockedOutAt = null,
    string $status = 'closed',
): string {
    $uuid = Str::uuid7()->toString();

    DB::table('shift_entries')->insert([
        'uuid' => $uuid,
        'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $clockedInAt,
        'clocked_out_at' => $clockedOutAt,
        'duration_minutes' => $clockedOutAt === null
            ? null
            : intdiv(strtotime($clockedOutAt) - strtotime($clockedInAt), 60),
        'status' => $clockedOutAt === null ? 'open' : $status,
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $clockedOutAt === null ? null : 'qr_kiosk',
        'version' => 1,
        'created_at' => $clockedInAt,
        'updated_at' => $clockedInAt,
    ]);

    return $uuid;
}

/**
 * Deja `daily_totals` **correcta**, agregando desde los tramos vigentes.
 *
 * Es la misma sentencia que usan los seeders de desarrollo, y se usa aqui para
 * montar el punto de partida sano: lo que estas pruebas comprueban es que pasa
 * cuando alguien lo estropea DESPUES.
 */
function projectDailyTotals(string $recalculatedAt = '2026-03-14 12:00:00+00'): void
{
    DB::statement(<<<'SQL'
        INSERT INTO daily_totals (
            employee_id, work_date, total_minutes, shift_count,
            first_in_at, last_out_at, has_open_shift, has_incident, recalculated_at
        )
        SELECT employee_id,
               work_date,
               COALESCE(SUM(duration_minutes), 0),
               COUNT(*),
               MIN(clocked_in_at),
               MAX(clocked_out_at),
               BOOL_OR(clocked_out_at IS NULL),
               BOOL_OR(status = 'anomalous'),
               ?::timestamptz
          FROM shift_entries
         WHERE status NOT IN ('voided', 'superseded')
         GROUP BY employee_id, work_date
        ON CONFLICT (employee_id, work_date) DO UPDATE
           SET total_minutes   = EXCLUDED.total_minutes,
               shift_count     = EXCLUDED.shift_count,
               first_in_at     = EXCLUDED.first_in_at,
               last_out_at     = EXCLUDED.last_out_at,
               has_open_shift  = EXCLUDED.has_open_shift,
               has_incident    = EXCLUDED.has_incident,
               recalculated_at = EXCLUDED.recalculated_at
    SQL, [$recalculatedAt]);
}

/**
 * Ejecuta el comando y devuelve su codigo de salida.
 *
 * Se llama al comando entero y no al caso de uso: el codigo de salida forma
 * parte de lo que la tarea pide —distinto de cero si hubo divergencias— y es lo
 * que el planificador registra.
 */
function runReconcile(string $from, string $to, string $now = RECONCILIATION_NOW): int
{
    app()->instance(Clock::class, FixedClock::at($now));

    return Artisan::call('attendance:reconcile', ['--from' => $from, '--to' => $to]);
}

function projectionMetricsFile(): string
{
    return rtrim(config()->string('observability.metrics.textfile_path'), '/').'/kronoqr_projection.prom';
}

/** El contador queda escrito entre pasadas: se borra antes de afirmar sobre el. */
function forgetProjectionMetrics(): void
{
    @unlink(projectionMetricsFile());
}

function publishedProjectionMetrics(): string
{
    return (string) file_get_contents(projectionMetricsFile());
}

/**
 * La fila de la proyeccion de una jornada, o `null` si no existe.
 */
function projectedRow(string $employeeUuid, string $workDate): ?object
{
    $row = DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employeeUuid))
        ->where('work_date', $workDate)
        ->first();

    return is_object($row) ? $row : null;
}

/**
 * La misma fila, leida con tipos.
 *
 * Se usa {@see Row} —el lector tipado del producto— y no `(int) $row->columna`:
 * el driver de PostgreSQL no promete si un `integer` llega como `int` o como
 * `string`, y una prueba que se apoye en el molde afirma menos de lo que parece.
 */
function projectedTotals(string $employeeUuid, string $workDate): Row
{
    $row = projectedRow($employeeUuid, $workDate);

    return Row::of($row ?? throw new RuntimeException(
        'No hay fila de daily_totals para '.$employeeUuid.' el '.$workDate.'.'
    ));
}

it('corrige la fila corrompida a mano y cuenta la divergencia', function (): void {
    // EL ESCENARIO INELUDIBLE del doc 02 §9.4: corromper deliberadamente
    // `daily_totals`, ejecutar `attendance:reconcile` y verificar la correccion y
    // la alerta. La corrupcion se hace por SQL, por fuera de la aplicacion, que
    // es la unica forma en que esto puede ocurrir de verdad (regla dura 7).
    $scenario = reconciliationScenario();
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    projectDailyTotals();

    DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($scenario['employee']))
        ->update(['total_minutes' => 60, 'shift_count' => 9, 'has_open_shift' => true]);

    forgetProjectionMetrics();

    // Distinto de cero aunque lo haya corregido todo: una divergencia no es
    // trabajo rutinario, es un incidente de integridad.
    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(1);

    $row = projectedTotals($scenario['employee'], '2026-03-14');

    expect($row->int('total_minutes'))->toBe(480)
        ->and($row->int('shift_count'))->toBe(1)
        ->and($row->bool('has_open_shift'))->toBeFalse();

    // Y la comprobacion de integridad, que es la que no puede fallar nunca.
    expect(AttendanceFixtures::projectionDivergences())->toBe([]);

    // La metrica del doc 02 §8.2, que es lo que dispara la alerta critica.
    expect(publishedProjectionMetrics())
        ->toContain('projection_divergence_total 1')
        ->toContain('projection_reconciliation_last_corrections 1')
        ->toContain('projection_reconciliation_last_run_timestamp_seconds');
})->group('RF-PR-02', 'RN-06');

it('no reescribe ninguna fila ni incrementa la metrica cuando todo cuadra', function (): void {
    // El caso normal, que es el que ocurre todas las noches. Se comprueba con
    // `recalculated_at`: si la reconciliacion reescribiera «por si acaso», esa
    // marca cambiaria, y con ella se perderia la unica pista de cuando se
    // calculo de verdad el total de un dia.
    $scenario = reconciliationScenario();
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    projectDailyTotals('2026-03-14 14:00:05+00');

    $before = projectedTotals($scenario['employee'], '2026-03-14')->instant('recalculated_at');
    forgetProjectionMetrics();

    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(0);

    $after = projectedTotals($scenario['employee'], '2026-03-14');

    expect($after->instant('recalculated_at')->format('U.u'))->toBe($before->format('U.u'))
        ->and($after->int('total_minutes'))->toBe(480);

    expect(publishedProjectionMetrics())
        ->toContain('projection_divergence_total 0')
        ->toContain('projection_reconciliation_last_corrections 0')
        // Se publica tambien cuando todo esta bien: una serie que desaparece es
        // indistinguible de una tarea que dejo de ejecutarse.
        ->toContain('projection_reconciliation_work_days_inspected 1');
})->group('RF-PR-02');

it('cuadra con los eventos origen despues de una correccion y una anulacion', function (): void {
    // RN-13 y ADR-026: la version sustituida y el tramo anulado siguen en la
    // tabla —nada se borra— y NO cuentan. Si la reconciliacion sumara el
    // historico, el dia valdria 930 minutos en vez de 450, que es exactamente el
    // error que ADR-007 existe para impedir.
    $scenario = reconciliationScenario();
    $author = ManagementUsers::withRole(UserRole::RRHH);
    app()->instance(Clock::class, FixedClock::at('2026-03-16 09:00:00'));

    $corregible = reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-15', '2026-03-15 06:00:00+00', '2026-03-15 14:00:00+00');
    $duplicado = reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-15', '2026-03-15 15:00:00+00', '2026-03-15 17:00:00+00');

    app(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $corregible,
        clockedInAt: null,
        clockedOutAt: Instants::utc('2026-03-15 13:30'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_SALIDA'),
        performedByUserId: $author->id,
    ));

    app(VoidShiftHandler::class)->handle(new VoidShiftCommand(
        shiftEntryUuid: $duplicado,
        reason: CorrectionReason::fromCode('ERROR_DE_ESCANEO_DUPLICADO'),
        performedByUserId: $author->id,
    ));

    // El historico esta ahi: una version sustituida y un tramo anulado.
    expect(DB::table('shift_entries')->whereIn('status', ['superseded', 'voided'])->count())->toBe(2);

    // Se borra la proyeccion entera para forzar la reconstruccion desde cero,
    // que es la verificacion que pide ADR-007: reconstruir tiene que dar lo
    // mismo que habia.
    DB::table('daily_totals')->delete();
    forgetProjectionMetrics();

    expect(runReconcile('2026-03-15', '2026-03-15'))->toBe(1);

    $row = projectedTotals($scenario['employee'], '2026-03-15');

    expect($row->int('total_minutes'))->toBe(450)
        ->and($row->int('shift_count'))->toBe(1);

    expect(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-06', 'RN-13');

it('atribuye el turno nocturno a la jornada de inicio al reconstruir', function (): void {
    // RN-05 y regla dura 4: un turno 22:00 -> 06:00 es un unico tramo del dia en
    // que empezo. Si la reconstruccion lo partiera por medianoche, el 14 tendria
    // dos horas y el 15 seis, y el registro de dos personas del mismo turno
    // dependeria de la hora a la que ficharon.
    $scenario = reconciliationScenario();

    // 22:00 y 06:00 hora de Madrid, ya en UTC.
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 21:00:00+00', '2026-03-15 05:00:00+00');

    expect(runReconcile('2026-03-14', '2026-03-15'))->toBe(1);

    expect(projectedTotals($scenario['employee'], '2026-03-14')->int('total_minutes'))->toBe(480);
    // Y el dia siguiente no existe: no se le ha imputado ni un minuto.
    expect(projectedRow($scenario['employee'], '2026-03-15'))->toBeNull();
})->group('RN-05');

it('reconstruye las horas reales de los dos cambios de hora', function (): void {
    // RN-09. En Madrid, el ultimo domingo de marzo los relojes saltan de 02:00 a
    // 03:00 y el de octubre las 02:00 ocurren dos veces. Las dos jornadas duran
    // ocho horas de reloj de pared y siete y nueve de verdad: lo que se paga son
    // las de verdad, y por eso el total se recompone sobre instantes UTC.
    $scenario = reconciliationScenario();
    $otro = WorkforceFixtures::employee($scenario['site']);

    // 28-mar 23:00 -> 29-mar 07:00 hora local = 22:00Z -> 05:00Z = 7 h.
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-28', '2026-03-28 22:00:00+00', '2026-03-29 05:00:00+00');
    // 24-oct 23:00 -> 25-oct 07:00 hora local = 21:00Z -> 06:00Z = 9 h.
    reconciledShiftEntry($otro, $scenario['site'], '2026-10-24', '2026-10-24 21:00:00+00', '2026-10-25 06:00:00+00');

    expect(runReconcile('2026-03-28', '2026-10-24'))->toBe(1);

    expect(projectedTotals($scenario['employee'], '2026-03-28')->int('total_minutes'))->toBe(420);
    expect(projectedTotals($otro, '2026-10-24')->int('total_minutes'))->toBe(540);
})->group('RN-09');

it('crea la fila que faltaba en la proyeccion', function (): void {
    // Una jornada con tramos y sin fila es la peor de las divergencias: el panel
    // muestra el dia vacio y el informe no lo suma, y ninguna consulta guiada por
    // `daily_totals` la encontraria nunca.
    $scenario = reconciliationScenario();
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');

    expect(projectedRow($scenario['employee'], '2026-03-14'))->toBeNull();

    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(1);

    $row = projectedTotals($scenario['employee'], '2026-03-14');

    expect($row->int('total_minutes'))->toBe(480)
        ->and($row->int('shift_count'))->toBe(1)
        ->and($row->nullableInstant('first_in_at'))->not->toBeNull()
        ->and($row->nullableInstant('last_out_at'))->not->toBeNull();
})->group('RF-PR-02');

it('deja a cero la jornada anulada por completo, sin borrar la fila', function (): void {
    // Regla dura 5: nada se borra. El dia siguio existiendo aunque su contenido
    // se anulara, y borrar la fila haria desaparecer del panel una jornada sobre
    // la que alguien tomo una decision.
    $scenario = reconciliationScenario();
    $entry = reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    projectDailyTotals();

    // Se anula por SQL para dejar la proyeccion desactualizada a proposito: por
    // el camino bueno —`VoidShiftHandler`— la fila se recalcularia sola, que es
    // justo lo que hace que esta divergencia no pueda existir en produccion.
    DB::table('shift_entries')->where('uuid', $entry)->update(['status' => 'voided']);

    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(1);

    expect(projectedRow($scenario['employee'], '2026-03-14'))->not->toBeNull();

    $row = projectedTotals($scenario['employee'], '2026-03-14');

    expect($row->int('total_minutes'))->toBe(0)
        ->and($row->int('shift_count'))->toBe(0)
        ->and($row->nullableInstant('first_in_at'))->toBeNull()
        ->and($row->bool('has_open_shift'))->toBeFalse();
})->group('RF-PR-02', 'RN-13');

it('no encuentra nada en la segunda pasada', function (): void {
    // Idempotencia. Es la propiedad que permite lanzar el comando sobre un rango
    // ancho sin pensarlo dos veces despues de una restauracion o una migracion
    // (doc 02 §10.4).
    $scenario = reconciliationScenario();
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');

    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(1);

    $row = projectedTotals($scenario['employee'], '2026-03-14')->instant('recalculated_at');
    forgetProjectionMetrics();

    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(0);

    expect(projectedTotals($scenario['employee'], '2026-03-14')->instant('recalculated_at')->format('U.u'))
        ->toBe($row->format('U.u'));
    expect(publishedProjectionMetrics())->toContain('projection_divergence_total 0');
})->group('RF-PR-02');

it('deja asiento en audit_log por cada fila corregida, con el antes y el despues', function (): void {
    // Regla dura 6: si la reconciliacion corrige un agregado, la correccion queda
    // trazada. El asiento es la unica copia de lo que la proyeccion afirmaba que
    // no se puede tocar (ADR-027), y por eso lleva `before` y `after` completos.
    //
    // El listener lo registra `ComplianceServiceProvider::boot()`, junto a los de
    // `RecordShiftEntryAudit`: esta prueba lo ejercita tal como corre en produccion.

    $scenario = reconciliationScenario();
    reconciledShiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 14:00:00+00');
    projectDailyTotals();

    DB::table('daily_totals')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($scenario['employee']))
        ->update(['total_minutes' => 999]);

    expect(runReconcile('2026-03-14', '2026-03-14'))->toBe(1);

    $entry = DB::table('audit_log')->where('subject_type', 'daily_totals')->first();

    expect($entry)->not->toBeNull();

    $payload = Row::of($entry ?? throw new RuntimeException('La correccion no dejo asiento en audit_log.'))
        ->json('payload') ?? [];

    expect($payload['employee_uuid'] ?? null)->toBe($scenario['employee'])
        ->and($payload['work_date'] ?? null)->toBe('2026-03-14')
        ->and($payload['divergent_fields'] ?? null)->toBe(['total_minutes'])
        // `toEqualCanonicalizing` y no `toBe`: el payload se canonicaliza antes
        // de encadenarlo por hash y JSONB no conserva el orden de las claves.
        ->and($payload['before'] ?? null)->toEqualCanonicalizing(['total_minutes' => 999, 'shift_count' => 1])
        ->and($payload['after'] ?? null)->toEqualCanonicalizing(['total_minutes' => 480, 'shift_count' => 1]);

    // Regla dura 21: el asiento identifica por UUID y no lleva ningun nombre.
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Persona');
})->group('RF-PR-02', 'RL-04');

it('rechaza un rango al reves en vez de darle la vuelta en silencio', function (): void {
    // Quien escribio `--from=abril --to=marzo` creeria haber revisado marzo
    // entero. Corregirlo en silencio es peor que rechazarlo.
    reconciliationScenario();

    expect(runReconcile('2026-04-01', '2026-03-01'))->toBe(2);
})->group('RF-PR-02');
