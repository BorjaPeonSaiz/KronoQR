<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Database\CommittedDatabase;

/*
 * Las tres migraciones del bloque 18 (RN-20, RN-22, ADR-047), deshechas y
 * reaplicadas, y sus `down()` que se detienen a proposito (skill
 * `/migracion-segura`).
 *
 * Mismo planteamiento que `PinClaimMigrationsTest`: la vuelta atras PARCIAL, que
 * es la que se ejecuta en un servidor, con `CommittedDatabase` porque
 * `migrate:rollback` corre con el rol de migracion en otra conexion.
 *
 * Lo que distingue estos `down()`: el de `incidents` se niega si hay
 * incidencias `scan_before_revocation` o `discarded_scan`, y el de
 * `discarded_scan_reports` si hay avisos. Nada se borra (regla dura 5). El de
 * `devices` si vuelve atras con un tamaño desconocido: es telemetria del
 * aparato, y lo convierte en cero hasta el siguiente latido.
 */

uses(CommittedDatabase::class);

/** Las tres migraciones, en el orden en que se aplican. */
const WITHDRAWN_DISCARDED_MIGRATIONS = [
    '2026_10_03_100000_allow_withdrawn_and_discarded_scan_incident_types',
    '2026_10_03_100100_create_discarded_scan_reports_table',
    '2026_10_03_100200_add_queue_health_to_devices_table',
];

function withdrawnDiscardedConnection(): string
{
    return config()->string('database.migrations.connection');
}

/** Cuantos pasos deshacer para quedar justo antes de la migracion dada. */
function withdrawnDiscardedStepsBefore(string $migration): int
{
    /** @var list<string> $applied */
    $applied = DB::connection(withdrawnDiscardedConnection())->table('migrations')
        ->orderBy('id')
        ->pluck('migration')
        ->map(static fn (mixed $name): string => (string) $name) // @phpstan-ignore-line cast.string (`pluck` devuelve `mixed`; la columna es `varchar` y el nombre de una migracion siempre es texto)
        ->values()
        ->all();

    $position = array_search($migration, $applied, true);

    expect($position)->toBeInt($migration.' no esta aplicada.');

    /** @var int $position */
    return \count($applied) - $position;
}

function withdrawnDiscardedHasTable(string $table): bool
{
    return DB::connection(withdrawnDiscardedConnection())
        ->table('information_schema.tables')
        ->where('table_name', $table)
        ->exists();
}

function withdrawnDiscardedHasColumn(string $table, string $column): bool
{
    return DB::connection(withdrawnDiscardedConnection())
        ->table('information_schema.columns')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->exists();
}

function withdrawnDiscardedConstraint(string $name): string
{
    /** @var object{definition: string}|null $row */
    $row = DB::connection(withdrawnDiscardedConnection())->selectOne(
        'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
        [$name],
    );

    return $row === null ? '' : $row->definition;
}

function withdrawnDiscardedMigrate(): void
{
    [$migrated, $output] = Commands::run('migrate --database='.withdrawnDiscardedConnection());

    expect($migrated)->toBe(0, $output);
}

it('deshace y reaplica las tres migraciones del bloque 18', function (): void {
    $name = withdrawnDiscardedConnection();
    $steps = withdrawnDiscardedStepsBefore(WITHDRAWN_DISCARDED_MIGRATIONS[0]);

    expect(withdrawnDiscardedHasTable('discarded_scan_reports'))->toBeTrue()
        ->and(withdrawnDiscardedHasColumn('devices', 'queue_storage'))->toBeTrue()
        ->and(withdrawnDiscardedConstraint('incidents_chk_type'))->toContain('discarded_scan');

    [$rolledBack, $output] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

    expect($rolledBack)->toBe(0, $output)
        ->and(withdrawnDiscardedHasTable('discarded_scan_reports'))->toBeFalse()
        ->and(withdrawnDiscardedHasColumn('devices', 'queue_storage'))->toBeFalse()
        ->and(withdrawnDiscardedHasColumn('devices', 'unreported_discards'))->toBeFalse()
        ->and(withdrawnDiscardedConstraint('incidents_chk_type'))->not->toContain('scan_before_revocation')
        ->and(withdrawnDiscardedConstraint('incidents_chk_type'))->toContain('rejected_pin_scan');

    withdrawnDiscardedMigrate();

    expect(withdrawnDiscardedHasTable('discarded_scan_reports'))->toBeTrue()
        ->and(withdrawnDiscardedConstraint('devices_chk_unknown_queue_size_only_when_degraded'))->toContain('durable')
        ->and(DB::connection($name)->table('pg_constraint')->where('conname', 'devices_chk_queue_storage')->value('convalidated'))->toBeTrue()
        ->and(withdrawnDiscardedConstraint('incidents_chk_type'))->toContain('scan_before_revocation');
})->group('RN-20', 'RN-22', 'RF-PA-07', 'RNF-D-04');

it('vuelve atras con un tamano de cola desconocido convirtiendolo en cero', function (): void {
    $scenario = AttendanceFixtures::scenario();

    DB::table('devices')->where('id', $scenario['device'])->update([
        'pending_queue_size' => null,
        'queue_storage' => 'memory',
    ]);

    try {
        [$rolledBack, $output] = Commands::run('migrate:rollback --database='.withdrawnDiscardedConnection()
            .' --step='.withdrawnDiscardedStepsBefore(WITHDRAWN_DISCARDED_MIGRATIONS[2]));

        expect($rolledBack)->toBe(0, $output)
            ->and(DB::table('devices')->where('id', $scenario['device'])->value('pending_queue_size'))->toBe(0);
    } finally {
        withdrawnDiscardedMigrate();
    }
})->group('RF-PA-07', 'RNF-D-04');

it('se niega a borrar la tabla de avisos si ya guarda alguno', function (): void {
    $scenario = AttendanceFixtures::scenario();

    DB::table('discarded_scan_reports')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $scenario['device'],
        'origin' => 'pin_kiosk',
        'occurred_at' => '2026-08-15 06:59:00+00',
        'discarded_at' => '2026-08-15 07:10:00+00',
        'recorded_at' => '2026-08-15 10:00:00+00',
        'http_status' => 400,
        'owner_employee_id' => null,
        'attribution' => 'none',
        'already_recorded' => false,
    ]);

    try {
        expect(fn (): array => Commands::run('migrate:rollback --database='.withdrawnDiscardedConnection()
            .' --step='.withdrawnDiscardedStepsBefore(WITHDRAWN_DISCARDED_MIGRATIONS[1])))
            ->toThrow(RuntimeException::class, 'discarded_scan_reports');

        expect(withdrawnDiscardedHasTable('discarded_scan_reports'))->toBeTrue()
            ->and(DB::table('discarded_scan_reports')->count())->toBe(1);
    } finally {
        // La de `devices` si se deshizo; se reaplica.
        withdrawnDiscardedMigrate();
    }
})->group('RN-22', 'RNF-D-04');

it('se niega a reducir el catalogo de incidencias si hay alguna de las dos nuevas', function (string $type): void {
    $scenario = AttendanceFixtures::scenario();

    IncidentFixtures::open(
        $scenario['employee'],
        type: $type,
        severity: 'medium',
        context: ['scan_id' => Str::uuid7()->toString(), 'attempts' => 1],
    );

    try {
        expect(fn (): array => Commands::run('migrate:rollback --database='.withdrawnDiscardedConnection()
            .' --step='.withdrawnDiscardedStepsBefore(WITHDRAWN_DISCARDED_MIGRATIONS[0])))
            ->toThrow(QueryException::class, 'incidents_chk_type');

        expect(withdrawnDiscardedConstraint('incidents_chk_type'))->toContain($type);
    } finally {
        withdrawnDiscardedMigrate();
    }
})->with(['scan_before_revocation', 'discarded_scan'])->group('RN-20', 'RN-22', 'RNF-D-04');
