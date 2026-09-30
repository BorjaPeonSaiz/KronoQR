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
 * Las tres migraciones de RN-19, deshechas y reaplicadas, y sus dos `down()`
 * que se detienen a proposito (skill `/migracion-segura`, ADR-043).
 *
 * Mismo planteamiento que `OutOfOrderScanMigrationsTest`: la vuelta atras
 * PARCIAL, que es la que se ejecuta en un servidor, con `CommittedDatabase`
 * porque `migrate:rollback` corre con el rol de migracion en otra conexion.
 *
 * Lo que distingue estos `down()`: el de `scan_events` se niega a quitar las
 * columnas si alguna fila ya anota a quien correspondia un PIN, y el de
 * `incidents` se niega si hay incidencias `rejected_pin_scan`. Nada se borra
 * (regla dura 5).
 */

uses(CommittedDatabase::class);

/** Las tres migraciones de RN-19, en el orden en que se aplican. */
const PIN_CLAIM_MIGRATIONS = [
    '2026_09_30_120000_add_pin_claim_to_scan_events',
    '2026_09_30_120100_index_pin_claims_on_scan_events',
    '2026_09_30_120200_allow_rejected_pin_scan_incident_type',
];

function pinClaimMigrationsConnection(): string
{
    return config()->string('database.migrations.connection');
}

/** Cuantos pasos deshacer para quedar justo antes de la migracion dada. */
function pinClaimMigrationsStepsBefore(string $migration): int
{
    /** @var list<string> $applied */
    $applied = DB::connection(pinClaimMigrationsConnection())->table('migrations')
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

function pinClaimMigrationsHasColumn(string $column): bool
{
    return DB::connection(pinClaimMigrationsConnection())
        ->table('information_schema.columns')
        ->where('table_name', 'scan_events')
        ->where('column_name', $column)
        ->exists();
}

function pinClaimMigrationsHasIndex(): bool
{
    return DB::connection(pinClaimMigrationsConnection())
        ->table('pg_indexes')
        ->where('indexname', 'scan_events_pin_claims_recorded_at_index')
        ->exists();
}

function pinClaimMigrationsConstraint(string $name): string
{
    /** @var object{definition: string}|null $row */
    $row = DB::connection(pinClaimMigrationsConnection())->selectOne(
        'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
        [$name],
    );

    return $row === null ? '' : $row->definition;
}

function pinClaimMigrationsMigrate(): void
{
    [$migrated, $output] = Commands::run('migrate --database='.pinClaimMigrationsConnection());

    expect($migrated)->toBe(0, $output);
}

it('deshace y reaplica las tres migraciones de RN-19', function (): void {
    $name = pinClaimMigrationsConnection();
    $steps = pinClaimMigrationsStepsBefore(PIN_CLAIM_MIGRATIONS[0]);

    expect(pinClaimMigrationsHasColumn('claimed_employee_id'))->toBeTrue()
        ->and(pinClaimMigrationsHasColumn('pin_lockout'))->toBeTrue()
        ->and(pinClaimMigrationsHasIndex())->toBeTrue()
        ->and(pinClaimMigrationsConstraint('scan_events_chk_pin_claim'))->toContain('pin_kiosk')
        ->and(pinClaimMigrationsConstraint('incidents_chk_type'))->toContain('rejected_pin_scan');

    [$rolledBack, $output] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

    expect($rolledBack)->toBe(0, $output)
        ->and(pinClaimMigrationsHasColumn('claimed_employee_id'))->toBeFalse()
        ->and(pinClaimMigrationsHasColumn('pin_lockout'))->toBeFalse()
        ->and(pinClaimMigrationsHasIndex())->toBeFalse()
        ->and(pinClaimMigrationsConstraint('scan_events_chk_pin_claim'))->toBe('')
        ->and(pinClaimMigrationsConstraint('scan_events_claimed_employee_id_foreign'))->toBe('')
        ->and(pinClaimMigrationsConstraint('incidents_chk_type'))->not->toContain('rejected_pin_scan')
        // Lo anterior entero: RN-18 sigue admitido.
        ->and(pinClaimMigrationsConstraint('incidents_chk_type'))->toContain('out_of_order_scan');

    pinClaimMigrationsMigrate();

    expect(pinClaimMigrationsHasColumn('claimed_employee_id'))->toBeTrue()
        ->and(pinClaimMigrationsHasIndex())->toBeTrue()
        ->and(DB::connection($name)->table('pg_constraint')->where('conname', 'scan_events_chk_pin_claim')->value('convalidated'))->toBeTrue()
        ->and(DB::connection($name)->table('pg_constraint')->where('conname', 'scan_events_claimed_employee_id_foreign')->value('convalidated'))->toBeTrue()
        ->and(pinClaimMigrationsConstraint('incidents_chk_type'))->toContain('rejected_pin_scan');
})->group('RN-19', 'RF-PD-10', 'RNF-D-04');

it('se niega a quitar las columnas si algun intento ya anota a su dueño', function (): void {
    $scenario = AttendanceFixtures::scenario();

    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $scenario['device'],
        'employee_id' => null,
        'occurred_at' => '2026-03-14 07:00:00+00',
        'recorded_at' => '2026-03-14 07:00:00+00',
        'origin' => 'pin_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_unknown',
        'flagged_for_review' => false,
        'worked_minutes' => null,
        'client_meta' => '{}',
        'claimed_employee_id' => AttendanceFixtures::employeeIdOf($scenario['employee']),
        'pin_lockout' => false,
    ]);

    $steps = pinClaimMigrationsStepsBefore(PIN_CLAIM_MIGRATIONS[0]);

    try {
        expect(fn (): array => Commands::run('migrate:rollback --database='.pinClaimMigrationsConnection().' --step='.$steps))
            ->toThrow(RuntimeException::class, 'claimed_employee_id');

        // El dato sigue ahi: nada se ha borrado.
        expect(pinClaimMigrationsHasColumn('claimed_employee_id'))->toBeTrue()
            ->and(DB::table('scan_events')->whereNotNull('claimed_employee_id')->count())->toBe(1);
    } finally {
        // Las dos posteriores si se deshicieron; se reaplican para dejar el
        // esquema como lo encontro la suite.
        pinClaimMigrationsMigrate();
    }

    expect(pinClaimMigrationsHasIndex())->toBeTrue();
})->group('RN-19', 'RF-PD-10');

it('se niega a reducir el catalogo si hay incidencias rejected_pin_scan', function (): void {
    $scenario = AttendanceFixtures::scenario();

    IncidentFixtures::open(
        $scenario['employee'],
        type: 'rejected_pin_scan',
        severity: 'medium',
        context: ['scan_id' => Str::uuid7()->toString(), 'attempts' => 1],
    );

    try {
        expect(fn (): array => Commands::run('migrate:rollback --database='.pinClaimMigrationsConnection().' --step='.pinClaimMigrationsStepsBefore(PIN_CLAIM_MIGRATIONS[2])))
            ->toThrow(QueryException::class, 'incidents_chk_type');

        expect(pinClaimMigrationsConstraint('incidents_chk_type'))->toContain('rejected_pin_scan');
    } finally {
        pinClaimMigrationsMigrate();
    }
})->group('RN-19', 'RF-PD-10');
