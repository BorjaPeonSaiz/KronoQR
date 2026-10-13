<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La purga del registro horario pasa por una funcion que calcula su propio
 * corte (ADR-057 §3, bloque 7 de la 2.2.1; RL-02, RL-04, RS-07, RN-13).
 *
 * Contra PostgreSQL de verdad y con `fichaje_app` como conexion por defecto:
 * lo que se prueba es que el rol de la aplicacion **no puede** pedir un corte
 * posterior al que permiten el perfil y el suelo legal, por mucho que controle
 * el reloj de PHP. Las pruebas que cambian el perfil por debajo del suelo lo
 * hacen con el propietario, dentro de una transaccion que se deshace: es la
 * unica forma de llegar a ese estado, y es justo el que la funcion tiene que
 * resistir.
 */

uses(RefreshDatabase::class);

const PURGE_FUNCTION_MIGRATION = '2026_10_14_100000_work_record_trigger_and_purge_function';

/** Hoy en UTC segun el servidor de base de datos, que es el reloj de la funcion. */
function purgeFunctionToday(?ConnectionInterface $connection = null): DateTimeImmutable
{
    /** @var object{today: string} $row */
    $row = ($connection ?? DB::connection())->selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");

    return new DateTimeImmutable($row->today.' 00:00:00', new DateTimeZone('UTC'));
}

/** `today - N años`, con la aritmetica de `RetentionPolicy::workRecordCutoff()`. */
function purgeFunctionCutoff(int $years, ?ConnectionInterface $connection = null): string
{
    return purgeFunctionToday($connection)->sub(new DateInterval('P'.$years.'Y'))->format('Y-m-d');
}

/**
 * @return array<string, int>
 */
function purgeFunctionCall(string $cutoff, int $batch = 1000, ?ConnectionInterface $connection = null): array
{
    /** @var list<object{dataset: string, deleted: int|string}> $rows */
    $rows = ($connection ?? DB::connection())->select(
        'SELECT dataset, deleted FROM public.'.DatabaseWorkRecordArchive::PURGE_FUNCTION.'(?::date, ?::integer)',
        [$cutoff, $batch],
    );

    $result = [];

    foreach ($rows as $row) {
        $result[$row->dataset] = (int) $row->deleted;
    }

    return $result;
}

function purgeFunctionExpectRejected(string $what, Closure $call, string $code = '42501'): void
{
    try {
        DB::transaction(static function () use ($call): void {
            $call();
        });
    } catch (PDOException $exception) {
        expect($exception->getCode())->toBe($code, $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail('La funcion de purga ha aceptado «'.$what.'».');
}

/**
 * Con el propietario, en una transaccion que se deshace: el perfil por defecto
 * con `$jurisdiction` y `$years` aunque esten por debajo del suelo (se suelta el
 * `CHECK` del bloque 5 dentro de la misma transaccion).
 */
function purgeFunctionWithProfile(string $jurisdiction, int $years, Closure $assertions): void
{
    $owner = SchemaOwner::connection();
    $owner->beginTransaction();

    try {
        $owner->statement("SET LOCAL lock_timeout = '5s'");
        $owner->statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS compliance_profiles_chk_retention_floor_es');
        $owner->table('compliance_profiles')->where('is_default', true)->update([
            'jurisdiction' => $jurisdiction,
            'retention_years' => $years,
        ]);

        $assertions($owner);
    } finally {
        $owner->rollBack();
    }
}

function purgeFunctionOwnerRejects(ConnectionInterface $owner, string $cutoff): bool
{
    $owner->statement('SAVEPOINT purge_attempt');

    try {
        purgeFunctionCall($cutoff, 1000, $owner);
        $owner->statement('RELEASE SAVEPOINT purge_attempt');

        return false;
    } catch (PDOException $exception) {
        $owner->statement('ROLLBACK TO SAVEPOINT purge_attempt');

        expect($exception->getCode())->toBe('42501', $exception->getMessage());

        return true;
    }
}

/**
 * Un tramo de `$workDate` con todo lo que puede colgarle, escrito por la
 * aplicacion.
 *
 * @return array{entry: int, employee: int}
 */
function purgeFunctionEntry(string $workDate, int $corrections = 1, ?int $employeeId = null, int $version = 1, ?string $status = null): array
{
    $site = WorkforceFixtures::site();
    $employeeId ??= AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));
    $device = AttendanceFixtures::device($site);
    $manager = ManagementUsers::withRole(UserRole::ADMIN);
    $hour = 6 + $version;

    $entry = (int) DB::table('shift_entries')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $site,
        'work_date' => $workDate,
        'clocked_in_at' => sprintf('%s %02d:00:00+00', $workDate, $hour),
        'clocked_out_at' => sprintf('%s %02d:30:00+00', $workDate, $hour),
        'duration_minutes' => 30,
        'status' => $status ?? 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => $version,
        'created_at' => $workDate.' 06:00:00+00',
        'updated_at' => $workDate.' 06:00:00+00',
    ]);

    for ($i = 0; $i < $corrections; $i++) {
        DB::table('shift_corrections')->insert([
            'shift_entry_id' => $entry,
            'performed_by_user_id' => $manager->id,
            'action' => 'modified',
            'before' => json_encode(['n' => $i]),
            'after' => json_encode(['n' => $i + 1]),
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
            'reason_text' => null,
            'created_at' => $workDate.' 10:00:00+00',
        ]);
    }

    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $device['id'],
        'employee_id' => $employeeId,
        'occurred_at' => $workDate.' 06:00:00+00',
        'recorded_at' => $workDate.' 06:00:01+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'shift_entry_id' => $entry,
        'worked_minutes' => 0,
        'client_meta' => json_encode([]),
    ]);

    DB::table('incidents')->insert([
        'employee_id' => $employeeId,
        'work_date' => $workDate,
        'shift_entry_id' => $entry,
        'type' => 'short_shift',
        'severity' => 'low',
        'status' => 'open',
        'detected_at' => $workDate.' 20:00:00+00',
        'context' => json_encode([]),
        'created_at' => $workDate.' 20:00:00+00',
        'updated_at' => $workDate.' 20:00:00+00',
    ]);

    DB::table('daily_totals')->insertOrIgnore([
        'employee_id' => $employeeId,
        'work_date' => $workDate,
        'total_minutes' => 30,
        'shift_count' => 1,
        'recalculated_at' => $workDate.' 23:00:00+00',
    ]);

    return ['entry' => $entry, 'employee' => $employeeId];
}

function purgeFunctionStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === PURGE_FUNCTION_MIGRATION) {
            return $steps;
        }
    }

    return 0;
}

function purgeFunctionExists(ConnectionInterface $connection, string $signature): bool
{
    /** @var object{oid: string|null} $row */
    $row = $connection->selectOne('SELECT to_regprocedure(?)::text AS oid', ['public.'.$signature]);

    return $row->oid !== null;
}

it('la puede ejecutar la aplicacion y nadie mas, y sus ayudantes nadie', function (): void {
    $application = Config::string('database.roles.application');
    $maintenance = Config::string('database.roles.maintenance', 'fichaje_maintenance');

    $can = static function (string $role, string $function): bool {
        /** @var object{granted: bool} $row */
        $row = DB::selectOne('SELECT has_function_privilege(?, ?, \'EXECUTE\') AS granted', [$role, 'public.'.$function]);

        return (bool) $row->granted;
    };

    expect($can($application, 'work_record_purge_batch(date, integer)'))->toBeTrue()
        ->and($can('public', 'work_record_purge_batch(date, integer)'))->toBeFalse()
        ->and($can($maintenance, 'work_record_purge_batch(date, integer)'))->toBeFalse()
        ->and($can('public', 'work_record_retention_floor(text)'))->toBeFalse()
        ->and($can($application, 'work_record_retention_floor(text)'))->toBeFalse()
        ->and($can('public', 'work_record_purge_max_cutoff(date, integer)'))->toBeFalse()
        ->and($can($application, 'work_record_purge_max_cutoff(date, integer)'))->toBeFalse();

    /** @var object{definer: bool, owner: string, config: string} $function */
    $function = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer, pg_get_userbyid(p.proowner) AS owner, array_to_string(p.proconfig, ';') AS config
          FROM pg_proc p
         WHERE p.oid = 'public.work_record_purge_batch(date, integer)'::regprocedure
        SQL);

    expect($function->definer)->toBeTrue()
        ->and($function->owner)->toBe(Config::string('database.roles.migration'))
        ->and($function->config)->toContain('search_path=pg_catalog, pg_temp')
        ->and($function->config)->toContain('lock_timeout=5s');
})->group('RS-07', 'RL-02', 'RL-04');

it('rechaza un corte posterior al que permiten el perfil y el suelo', function (): void {
    WorkforceFixtures::site();

    // El perfil de serie: ES, 4 años.
    $maximum = purgeFunctionCutoff(4);
    $tomorrow = (new DateTimeImmutable($maximum))->modify('+1 day')->format('Y-m-d');

    purgeFunctionExpectRejected('un dia despues del admisible', static fn () => purgeFunctionCall($tomorrow));
    purgeFunctionExpectRejected('la jornada de ayer', static fn () => purgeFunctionCall(purgeFunctionToday()->modify('-1 day')->format('Y-m-d')));
    purgeFunctionExpectRejected('un corte futuro', static fn () => purgeFunctionCall('2999-01-01'));

    // El admisible, exacto, pasa (y sin nada que borrar, no borra nada).
    expect(array_sum(purgeFunctionCall($maximum)))->toBe(0);
})->group('RS-07', 'RL-02', 'RL-04', 'RN-13');

it('rechaza un lote fuera de rango o un corte nulo', function (int $batch): void {
    WorkforceFixtures::site();

    purgeFunctionExpectRejected('lote '.$batch, static fn () => purgeFunctionCall(purgeFunctionCutoff(5), $batch), '22023');
})->with([0, -1, DatabaseWorkRecordArchive::MAX_BATCH + 1])->group('RS-07', 'RL-02');

it('no baja del suelo de la jurisdiccion aunque el perfil diga menos', function (): void {
    // Un perfil español a un año solo existe si alguien suelta el `CHECK` del
    // bloque 5 con el rol de migracion. Ni asi: la funcion juzga con 4.
    purgeFunctionWithProfile('ES', 1, static function (ConnectionInterface $owner): void {
        expect(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(1, $owner)))->toBeTrue()
            ->and(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(3, $owner)))->toBeTrue()
            ->and(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(4, $owner)))->toBeFalse();
    });

    // Una subdivision hereda el suelo de su pais.
    purgeFunctionWithProfile('ES-CN', 2, static function (ConnectionInterface $owner): void {
        expect(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(2, $owner)))->toBeTrue()
            ->and(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(4, $owner)))->toBeFalse();
    });

    // Un pais fuera del catalogo recibe `default` (1): ahi manda el perfil.
    purgeFunctionWithProfile('PT', 2, static function (ConnectionInterface $owner): void {
        expect(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(1, $owner)))->toBeTrue()
            ->and(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(2, $owner)))->toBeFalse();
    });

    // Y un plazo por encima del suelo se respeta: 6 años son 6.
    purgeFunctionWithProfile('ES', 6, static function (ConnectionInterface $owner): void {
        expect(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(5, $owner)))->toBeTrue()
            ->and(purgeFunctionOwnerRejects($owner, purgeFunctionCutoff(6, $owner)))->toBeFalse();
    });
})->group('RS-07', 'RL-02', 'RL-04');

it('el suelo de la funcion es el del catalogo compliance.retention_floors', function (): void {
    // El dato esta en dos sitios a proposito (ADR-057 §5): si alguien añade un
    // pais al catalogo sin la migracion que reescribe la funcion, esto falla.
    $owner = SchemaOwner::connection();
    $floor = static function (string $jurisdiction) use ($owner): int {
        /** @var object{years: int} $row */
        $row = $owner->selectOne('SELECT public.work_record_retention_floor(?) AS years', [$jurisdiction]);

        return (int) $row->years;
    };

    /** @var array<string, int> $catalog */
    $catalog = Config::array('compliance.retention_floors.jurisdictions');
    $default = Config::integer('compliance.retention_floors.default');

    expect($catalog)->not->toBeEmpty();

    foreach ($catalog as $country => $years) {
        expect($floor($country))->toBe($years, $country)
            ->and($floor($country.'-XX'))->toBe($years, $country.'-XX');
    }

    foreach (['ZZ', 'PT', 'FR', ''] as $outside) {
        if (! array_key_exists($outside, $catalog)) {
            expect($floor($outside))->toBe($default, 'fuera del catalogo: '.$outside);
        }
    }
})->group('RL-02');

it('calcula el corte con la misma aritmetica que RetentionPolicy', function (string $today, int $years): void {
    $owner = SchemaOwner::connection();

    /** @var object{cutoff: string} $row */
    $row = $owner->selectOne('SELECT public.work_record_purge_max_cutoff(?::date, ?)::text AS cutoff', [$today, $years]);

    $policy = RetentionPolicy::of(
        legalRecordYears: $years,
        technicalLogDays: 90,
        errorHistoryDays: 90,
        legalRecordFloor: LegalRetentionFloor::byDefault(Jurisdiction::fromCode('PT'), 1),
    );
    $php = $policy->workRecordCutoff(new DateTimeImmutable($today.' 13:00:00', new DateTimeZone('UTC')));

    expect($row->cutoff)->toBe($php->format('Y-m-d'));
})->with([
    'un dia cualquiera' => ['2026-10-13', 4],
    '29 de febrero a 4 años' => ['2028-02-29', 4],
    '29 de febrero a 1 año (PHP desborda al 1 de marzo)' => ['2028-02-29', 1],
    '29 de febrero a 5 años' => ['2028-02-29', 5],
    '31 de diciembre' => ['2026-12-31', 4],
    '1 de enero' => ['2027-01-01', 6],
])->group('RL-02');

it('borra en orden, por lotes y por generaciones, y devuelve lo borrado', function (): void {
    $cutoff = purgeFunctionCutoff(4);
    $expired = (new DateTimeImmutable($cutoff))->modify('-10 days')->format('Y-m-d');

    // Un tramo vencido con tres correcciones (mas que el lote) y una cadena de
    // versiones vencida (v1 -> v2) en otra jornada.
    purgeFunctionEntry($expired, corrections: 3);
    $old = purgeFunctionEntry($expired, corrections: 0, version: 1, status: 'superseded', employeeId: null);
    $new = purgeFunctionEntry($expired, corrections: 1, employeeId: $old['employee'], version: 2);

    DB::table('shift_entries')->where('id', $old['entry'])->update(['superseded_by_id' => $new['entry']]);

    // Lo vigente: la jornada del dia del corte.
    $current = purgeFunctionEntry($cutoff);

    // Un escaneo rechazado viejo, sin tramo, y un aviso de fichaje descartado.
    $device = AttendanceFixtures::device(WorkforceFixtures::site());
    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(), 'device_id' => $device['id'], 'employee_id' => null,
        'occurred_at' => $expired.' 05:00:00+00', 'recorded_at' => $expired.' 05:00:01+00',
        'origin' => 'qr_kiosk', 'intent' => 'auto', 'result' => 'rejected_unknown',
        'shift_entry_id' => null, 'worked_minutes' => null, 'client_meta' => json_encode([]),
    ]);

    $totals = [];
    $rounds = 0;

    do {
        $round = purgeFunctionCall($cutoff, 2);
        $rounds++;

        foreach ($round as $dataset => $deleted) {
            $totals[$dataset] = ($totals[$dataset] ?? 0) + $deleted;
        }
    } while (array_sum($round) > 0 && $rounds < 20);

    expect($rounds)->toBeLessThan(20)
        ->and($totals)->toBe([
            'shift_corrections' => 4,
            'incidents' => 3,
            'scan_events' => 4,
            'discarded_scan_reports' => 0,
            'shift_entries' => 3,
            'daily_totals' => 2,
        ])
        // Lo vigente sigue entero.
        ->and(DB::table('shift_entries')->pluck('id')->all())->toBe([$current['entry']])
        ->and(DB::table('shift_corrections')->count())->toBe(1)
        ->and(DB::table('incidents')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(1)
        ->and(DB::table('daily_totals')->where('work_date', $cutoff)->count())->toBe(1);

})->group('RS-07', 'RL-02', 'RL-04', 'RN-13');

it('una tabla temporal del rol de la aplicacion no la desvia', function (): void {
    $cutoff = purgeFunctionCutoff(4);
    $expired = (new DateTimeImmutable($cutoff))->modify('-1 day')->format('Y-m-d');
    $entry = purgeFunctionEntry($expired, corrections: 0);

    // `PUBLIC` tiene `TEMPORARY`: sin `pg_temp` al final del `search_path`, una
    // tabla temporal homonima se resolveria antes que la del esquema.
    DB::statement('CREATE TEMP TABLE shift_entries (id bigint, work_date date, superseded_by_id bigint)');
    DB::statement('CREATE TEMP TABLE compliance_profiles (id bigint, retention_years integer, jurisdiction text, is_default boolean)');
    DB::statement("INSERT INTO pg_temp.compliance_profiles VALUES (1, 1, 'PT', true)");

    // Con su perfil falso, un año valdria; la funcion sigue usando el de verdad.
    purgeFunctionExpectRejected('perfil temporal', static fn () => purgeFunctionCall(purgeFunctionCutoff(1)));

    do {
        $round = purgeFunctionCall($cutoff);
    } while (array_sum($round) > 0);

    expect(DB::table('public.shift_entries')->where('id', $entry['entry'])->exists())->toBeFalse();
})->group('RS-07', 'RL-02');

it('su down() devuelve el DELETE y suelta trigger y funciones, y su up() lo vuelve a poner', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $application = Config::string('database.roles.application');

    $steps = purgeFunctionStepsBack($migrator);

    expect($steps)->toBeGreaterThan(0);

    $canDelete = static function (string $table) use ($migrator, $application): bool {
        /** @var object{granted: bool} $row */
        $row = $migrator->selectOne('SELECT has_table_privilege(?, ?, \'DELETE\') AS granted', [$application, $table]);

        return (bool) $row->granted;
    };

    $triggers = static function () use ($migrator): array {
        return $migrator->table('pg_trigger')->where('tgisinternal', false)->orderBy('tgname')->pluck('tgname')->all();
    };

    $tables = ['shift_entries', 'shift_corrections', 'scan_events', 'discarded_scan_reports', 'incidents', 'daily_totals', 'sites'];

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($back)->toBe(0, $backOutput)
            ->and(purgeFunctionExists($migrator, 'work_record_purge_batch(date, integer)'))->toBeFalse()
            ->and(purgeFunctionExists($migrator, 'shift_entries_guard_transition()'))->toBeFalse()
            ->and($triggers())->not->toContain('shift_entries_guard_transition', 'shift_entries_superseded_is_linked', 'incidents_guard_transition');

        foreach ($tables as $table) {
            expect($canDelete($table))->toBeTrue($table);
        }
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(purgeFunctionExists($migrator, 'work_record_purge_batch(date, integer)'))->toBeTrue()
        ->and($triggers())->toContain('shift_entries_guard_transition', 'shift_entries_superseded_is_linked', 'incidents_guard_transition');

    foreach ($tables as $table) {
        expect($canDelete($table))->toBeFalse($table);
    }
})->group('RS-07', 'RL-04');
