<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El rol de la aplicacion no reescribe el registro horario (ADR-057 §1,
 * bloque 6 de la 2.2.1; RL-04, RS-07, RN-13, reglas duras 5 y 6).
 *
 * Contra PostgreSQL de verdad y con `fichaje_app` como conexion por defecto:
 * una prueba de privilegios con el propietario pasaria siempre. La primera
 * prueba lo afirma, porque todo lo demas depende de ello.
 *
 * Tres cosas: lo que dice el catalogo (es lo que `verify_privileges` de
 * `update.sh` compara), lo que el motor rechaza con `42501` y lo que la
 * aplicacion sigue pudiendo hacer, que es exactamente lo que el dominio produce.
 * Los recorridos completos (fichar, cerrar, corregir, anular, resolver) los
 * ejercitan las suites Feature e Integration, que corren con este mismo rol.
 */

uses(RefreshDatabase::class);

const WORK_RECORD_PRIVILEGES_MIGRATION = '2026_10_13_100000_restrict_application_updates_on_work_record';

/**
 * @var array<string, list<string>>
 */
const WORK_RECORD_PRIVILEGES_UPDATABLE = [
    // `closed_by` desde la 2.3.0 (RN-05): se escribe al cerrar un tramo abierto,
    // con la salida. Va al final porque la lista sigue el orden de las columnas.
    'shift_entries' => ['clocked_out_at', 'duration_minutes', 'status', 'clock_out_source', 'superseded_by_id', 'updated_at', 'closed_by'],
    'incidents' => ['status', 'notified_at', 'resolved_at', 'resolved_by_user_id', 'resolution_note', 'updated_at'],
];

/**
 * @var list<string>
 */
const WORK_RECORD_PRIVILEGES_TABLES = [
    'shift_entries', 'shift_corrections', 'scan_events', 'discarded_scan_reports', 'incidents', 'daily_totals',
];

/**
 * @return array<string, bool>
 */
function workRecordTablePrivileges(string $table, ?ConnectionInterface $connection = null): array
{
    $connection ??= DB::connection();
    $application = Config::string('database.roles.application');
    $result = [];

    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
        /** @var object{granted: bool}|null $row */
        $row = $connection->selectOne('SELECT has_table_privilege(?, ?, ?) AS granted', [$application, $table, $privilege]);
        $result[$privilege] = (bool) $row?->granted;
    }

    return $result;
}

/**
 * Las columnas de la tabla sobre las que el rol de la aplicacion tiene `UPDATE`,
 * por tabla o por columna: `has_column_privilege` mira los dos.
 *
 * @return list<string>
 */
function workRecordUpdatableColumns(string $table, ?ConnectionInterface $connection = null): array
{
    $connection ??= DB::connection();

    /** @var list<object{column_name: string}> $rows */
    $rows = $connection->select(
        'SELECT column_name FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = ?
            AND has_column_privilege(?, format(\'%I\', table_name), column_name, \'UPDATE\')
          ORDER BY ordinal_position',
        [$table, Config::string('database.roles.application')],
    );

    return array_map(static fn (object $row): string => $row->column_name, $rows);
}

function workRecordExpectDenied(string $what, Closure $write): void
{
    try {
        // Dentro de un SAVEPOINT: en PostgreSQL un error aborta la transaccion
        // entera de la prueba.
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501', $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail('PostgreSQL ha permitido «'.$what.'» al rol de la aplicacion.');
}

function workRecordStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === WORK_RECORD_PRIVILEGES_MIGRATION) {
            return $steps;
        }
    }

    return 0;
}

/**
 * Un tramo abierto escrito por la aplicacion, que es la unica forma en que nace.
 *
 * @return array{uuid: string, id: int, employee: string}
 */
function workRecordOpenEntry(): array
{
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $uuid = Str::uuid7()->toString();

    $id = DB::table('shift_entries')->insertGetId([
        'uuid' => $uuid,
        'employee_id' => AttendanceFixtures::employeeIdOf($employee),
        'site_id' => $site,
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 08:00:00+00',
        'status' => 'open',
        'clock_in_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-03-14 08:00:00+00',
        'updated_at' => '2026-03-14 08:00:00+00',
    ]);

    return ['uuid' => $uuid, 'id' => $id, 'employee' => $employee];
}

it('corre con el rol de la aplicacion, no con el de migracion', function (): void {
    /** @var object{role: string} $row */
    $row = DB::selectOne('SELECT current_user AS role');

    expect($row->role)->toBe(Config::string('database.roles.application'));
})->group('RS-07', 'RL-04');

it('el catalogo concede a la aplicacion exactamente lo de ADR-057 §1', function (string $table, bool $update): void {
    // Sin `DELETE` desde el bloque 7: la purga de retencion pasa por la
    // funcion `SECURITY DEFINER` `work_record_purge_batch` (ADR-057 §3).
    expect(workRecordTablePrivileges($table))->toBe([
        'SELECT' => true,
        'INSERT' => true,
        'UPDATE' => $update,
        'DELETE' => false,
        'TRUNCATE' => false,
        'REFERENCES' => false,
        'TRIGGER' => false,
    ]);
})->with([
    'shift_entries (solo por columna)' => ['shift_entries', false],
    'shift_corrections' => ['shift_corrections', false],
    'scan_events' => ['scan_events', false],
    'discarded_scan_reports' => ['discarded_scan_reports', false],
    'incidents (solo por columna)' => ['incidents', false],
    'daily_totals (proyeccion, ADR-007)' => ['daily_totals', true],
])->group('RS-07', 'RL-04', 'RN-13');

it('UPDATE por columna solo sobre las que el dominio escribe', function (string $table): void {
    expect(workRecordUpdatableColumns($table))->toBe(WORK_RECORD_PRIVILEGES_UPDATABLE[$table]);
})->with(['shift_entries', 'incidents'])->group('RS-07', 'RL-04', 'RN-13');

it('ninguna columna de las tablas solo-append admite UPDATE', function (string $table): void {
    expect(workRecordUpdatableColumns($table))->toBe([]);
})->with(['shift_corrections', 'scan_events', 'discarded_scan_reports'])->group('RS-07', 'RL-04', 'RN-13');

it('el motor rechaza reescribir lo que el dominio no reescribe', function (string $what, Closure $write): void {
    workRecordExpectDenied($what, $write);
})->with([
    'cambiar la entrada de un tramo' => ['shift_entries.clocked_in_at', static fn () => DB::table('shift_entries')->update(['clocked_in_at' => '2026-01-01 00:00:00+00'])],
    'cambiar la version de un tramo (ADR-035)' => ['shift_entries.version', static fn () => DB::table('shift_entries')->update(['version' => 9])],
    'llevar un tramo a otra persona' => ['shift_entries.employee_id', static fn () => DB::table('shift_entries')->update(['employee_id' => 1])],
    'llevar un tramo a otra jornada (RN-05)' => ['shift_entries.work_date', static fn () => DB::table('shift_entries')->update(['work_date' => '2026-01-01'])],
    'cambiar el origen de la entrada' => ['shift_entries.clock_in_source', static fn () => DB::table('shift_entries')->update(['clock_in_source' => 'manual_admin'])],
    'reescribir una correccion (RN-13)' => ['shift_corrections.after', static fn () => DB::table('shift_corrections')->update(['after' => '{}'])],
    'cambiar el motivo de una correccion' => ['shift_corrections.reason_text', static fn () => DB::table('shift_corrections')->update(['reason_text' => 'x'])],
    'cambiar el resultado de un escaneo' => ['scan_events.result', static fn () => DB::table('scan_events')->update(['result' => 'accepted'])],
    'cambiar el instante de un escaneo' => ['scan_events.occurred_at', static fn () => DB::table('scan_events')->update(['occurred_at' => '2026-01-01 00:00:00+00'])],
    'reescribir un aviso de fichaje descartado' => ['discarded_scan_reports.attribution', static fn () => DB::table('discarded_scan_reports')->update(['attribution' => 'x'])],
    'cambiar el tipo de una incidencia' => ['incidents.type', static fn () => DB::table('incidents')->update(['type' => 'short_shift'])],
    'cambiar el contexto de una incidencia' => ['incidents.context', static fn () => DB::table('incidents')->update(['context' => '{}'])],
    'cambiar cuando se detecto una incidencia' => ['incidents.detected_at', static fn () => DB::table('incidents')->update(['detected_at' => '2026-01-01 00:00:00+00'])],
    'reasignar una incidencia' => ['incidents.assigned_to_user_id', static fn () => DB::table('incidents')->update(['assigned_to_user_id' => null])],
    'vaciar shift_entries' => ['TRUNCATE shift_entries', static fn () => DB::statement('TRUNCATE shift_entries CASCADE')],
    'vaciar shift_corrections' => ['TRUNCATE shift_corrections', static fn () => DB::statement('TRUNCATE shift_corrections')],
    'vaciar scan_events' => ['TRUNCATE scan_events', static fn () => DB::statement('TRUNCATE scan_events')],
    'vaciar discarded_scan_reports' => ['TRUNCATE discarded_scan_reports', static fn () => DB::statement('TRUNCATE discarded_scan_reports')],
    'vaciar incidents' => ['TRUNCATE incidents', static fn () => DB::statement('TRUNCATE incidents')],
    'vaciar daily_totals' => ['TRUNCATE daily_totals', static fn () => DB::statement('TRUNCATE daily_totals')],
])->group('RS-07', 'RL-04', 'RN-13');

it('el motor rechaza DELETE sobre el registro horario y sobre los centros', function (string $table): void {
    // Sin filas que borrar tambien: el privilegio se comprueba antes de leer
    // ninguna, asi que la prueba no depende de lo que haya sembrado.
    workRecordExpectDenied('DELETE '.$table, static fn () => DB::table($table)->where('id', '>', 0)->delete());
})->with([...WORK_RECORD_PRIVILEGES_TABLES, 'sites'])->group('RS-07', 'RL-04', 'RN-13', 'RL-02');

it('la aplicacion sigue pudiendo cerrar, superseder y anular un tramo', function (): void {
    $open = workRecordOpenEntry();

    // Cerrar: las columnas del `upsert` de EloquentWorkDayRepository.
    DB::table('shift_entries')->where('id', $open['id'])->update([
        'clocked_out_at' => '2026-03-14 16:00:00+00',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_out_source' => 'qr_kiosk',
        'updated_at' => '2026-03-14 16:00:00+00',
    ]);

    // Superseder, en el orden de EloquentWorkDayRepository: la version
    // antigua deja de ser vigente, la nueva es otra fila (ADR-035) y despues
    // se encadenan con `linkSupersededEntries`.
    DB::table('shift_entries')->where('id', $open['id'])->update(['status' => 'superseded', 'updated_at' => '2026-03-15 09:00:00+00']);

    $replacement = DB::table('shift_entries')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => AttendanceFixtures::employeeIdOf($open['employee']),
        'site_id' => WorkforceFixtures::onlySiteId(),
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 08:30:00+00',
        'clocked_out_at' => '2026-03-14 16:00:00+00',
        'duration_minutes' => 450,
        'status' => 'closed',
        'clock_in_source' => 'manual_admin',
        'clock_out_source' => 'manual_admin',
        'version' => 2,
        'created_at' => '2026-03-15 09:00:00+00',
        'updated_at' => '2026-03-15 09:00:00+00',
    ]);

    DB::table('shift_entries')->where('id', $open['id'])->update(['superseded_by_id' => $replacement, 'updated_at' => '2026-03-15 09:00:00+00']);

    // Anular la version vigente.
    DB::table('shift_entries')->where('id', $replacement)->update(['status' => 'voided', 'updated_at' => '2026-03-16 09:00:00+00']);

    expect(DB::table('shift_entries')->where('id', $open['id'])->value('superseded_by_id'))->toBe($replacement)
        ->and(DB::table('shift_entries')->where('id', $replacement)->value('status'))->toBe('voided');
})->group('RS-07', 'RL-04', 'RN-13');

it('el upsert de tramos de la aplicacion pasa con los privilegios de columna', function (): void {
    $open = workRecordOpenEntry();

    // La misma sentencia que compila `EloquentWorkDayRepository::persistEntries`:
    // `ON CONFLICT DO UPDATE` exige `UPDATE` sobre cada columna del `SET`.
    DB::table('shift_entries')->upsert([[
        'uuid' => $open['uuid'],
        'employee_id' => AttendanceFixtures::employeeIdOf($open['employee']),
        'site_id' => WorkforceFixtures::onlySiteId(),
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 08:00:00+00',
        'clocked_out_at' => '2026-03-14 15:00:00+00',
        'duration_minutes' => 420,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-03-14 08:00:00+00',
        'updated_at' => '2026-03-14 15:00:00+00',
    ]], ['uuid'], ['clocked_out_at', 'duration_minutes', 'status', 'clock_out_source', 'updated_at']);

    expect(DB::table('shift_entries')->where('id', $open['id'])->value('status'))->toBe('closed');

    // Y con `version` en el `SET`, como antes de la tarea 6.1, el motor lo
    // rechaza: es lo que habria roto en produccion.
    workRecordExpectDenied('upsert con version', static fn () => DB::table('shift_entries')->upsert([[
        'uuid' => $open['uuid'],
        'employee_id' => AttendanceFixtures::employeeIdOf($open['employee']),
        'site_id' => WorkforceFixtures::onlySiteId(),
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 08:00:00+00',
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'version' => 1,
    ]], ['uuid'], ['status', 'version']));
})->group('RS-07', 'RL-04', 'RN-13');

it('la aplicacion sigue pudiendo resolver una incidencia y marcarla avisada', function (): void {
    $site = WorkforceFixtures::site();
    $incident = IncidentFixtures::open(WorkforceFixtures::employee($site));

    DB::table('incidents')->where('id', $incident)->update([
        'notified_at' => '2026-03-15 04:00:00+00',
        'updated_at' => '2026-03-15 04:00:00+00',
    ]);

    DB::table('incidents')->where('id', $incident)->where('status', 'open')->update([
        'status' => 'dismissed',
        'resolved_at' => '2026-03-15 09:00:00+00',
        'resolved_by_user_id' => null,
        'resolution_note' => 'Revisado.',
        'updated_at' => '2026-03-15 09:00:00+00',
    ]);

    expect(IncidentFixtures::stored($incident)->status)->toBe('dismissed');
})->group('RS-07', 'RL-04', 'RN-13');

it('su down() devuelve exactamente lo que habia y su up() lo vuelve a quitar', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    $steps = workRecordStepsBack($migrator);

    expect($steps)->toBeGreaterThan(0);

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($back)->toBe(0, $backOutput);

        foreach (['shift_entries', 'shift_corrections', 'scan_events', 'incidents', 'daily_totals'] as $table) {
            expect(workRecordTablePrivileges($table, $migrator)['UPDATE'])->toBeTrue($table)
                ->and(workRecordTablePrivileges($table, $migrator)['DELETE'])->toBeTrue($table);
        }

        // Su migracion de creacion ya se lo quitaba (F7): no se devuelve.
        expect(workRecordTablePrivileges('discarded_scan_reports', $migrator)['UPDATE'])->toBeFalse();
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput);

    foreach (WORK_RECORD_PRIVILEGES_TABLES as $table) {
        expect(workRecordTablePrivileges($table, $migrator)['UPDATE'])->toBe($table === 'daily_totals', $table)
            // La migracion del bloque 7 vuelve con las demas y lo retira.
            ->and(workRecordTablePrivileges($table, $migrator)['DELETE'])->toBeFalse($table);
    }

    foreach (WORK_RECORD_PRIVILEGES_UPDATABLE as $table => $columns) {
        expect(workRecordUpdatableColumns($table, $migrator))->toBe($columns);
    }
})->group('RS-07', 'RL-04');
