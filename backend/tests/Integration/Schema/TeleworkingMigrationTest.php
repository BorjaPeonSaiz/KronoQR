<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `2026_10_01_100000_add_teleworking_to_employees_table`, deshecha y
 * reaplicada, y su `down()` que se detiene a proposito (skill
 * `/migracion-segura`, RF-GP-01).
 *
 * Mismo planteamiento que `PinClaimMigrationsTest`: la vuelta atras PARCIAL,
 * que es la que se ejecuta en un servidor, con `CommittedDatabase` porque
 * `migrate:rollback` corre con el rol de migracion en otra conexion.
 */

uses(CommittedDatabase::class);

const TELEWORKING_MIGRATION = '2026_10_01_100000_add_teleworking_to_employees_table';

function teleworkingMigrationConnection(): string
{
    return config()->string('database.migrations.connection');
}

/** Cuantos pasos deshacer para quedar justo antes de la migracion. */
function teleworkingMigrationSteps(): int
{
    /** @var list<string> $applied */
    $applied = DB::connection(teleworkingMigrationConnection())->table('migrations')
        ->orderBy('id')
        ->pluck('migration')
        ->map(static fn (mixed $name): string => (string) $name) // @phpstan-ignore-line cast.string (`pluck` devuelve `mixed`; la columna es `varchar` y el nombre de una migracion siempre es texto)
        ->values()
        ->all();

    $position = array_search(TELEWORKING_MIGRATION, $applied, true);

    expect($position)->toBeInt(TELEWORKING_MIGRATION.' no esta aplicada.');

    /** @var int $position */
    return \count($applied) - $position;
}

/**
 * @return object{data_type: string, is_nullable: string, column_default: string|null}|null
 */
function teleworkingMigrationColumn(): ?object
{
    /** @var object{data_type: string, is_nullable: string, column_default: string|null}|null $column */
    $column = DB::connection(teleworkingMigrationConnection())
        ->table('information_schema.columns')
        ->where('table_name', 'employees')
        ->where('column_name', 'teleworking')
        ->first(['data_type', 'is_nullable', 'column_default']);

    return $column;
}

function teleworkingMigrationMigrate(): void
{
    [$migrated, $output] = Commands::run('migrate --database='.teleworkingMigrationConnection());

    expect($migrated)->toBe(0, $output);
}

it('añade la columna booleana NOT NULL con false de serie, y la quita al deshacer', function (): void {
    $column = teleworkingMigrationColumn();

    expect($column)->not->toBeNull()
        ->and($column?->data_type)->toBe('boolean')
        ->and($column?->is_nullable)->toBe('NO')
        ->and($column?->column_default)->toBe('false');

    [$rolledBack, $output] = Commands::run(
        'migrate:rollback --database='.teleworkingMigrationConnection().' --step='.teleworkingMigrationSteps()
    );

    expect($rolledBack)->toBe(0, $output)
        ->and(teleworkingMigrationColumn())->toBeNull();

    teleworkingMigrationMigrate();

    expect(teleworkingMigrationColumn()?->column_default)->toBe('false');
})->group('RF-GP-01', 'RNF-D-04');

it('da false a las fichas que ya existian al aplicarla', function (): void {
    // Lo que dice el docblock de la migracion: `ADD COLUMN ... DEFAULT` en
    // PostgreSQL 11+ no reescribe las filas, pero todas leen el valor de serie.
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);

    [$rolledBack, $output] = Commands::run(
        'migrate:rollback --database='.teleworkingMigrationConnection().' --step='.teleworkingMigrationSteps()
    );

    expect($rolledBack)->toBe(0, $output);

    teleworkingMigrationMigrate();

    expect(DB::table('employees')->where('uuid', $uuid)->value('teleworking'))->toBeFalse();
})->group('RF-GP-01', 'RNF-D-04');

it('se niega a quitar la columna si alguna ficha esta marcada', function (): void {
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site);

    DB::table('employees')->where('uuid', $uuid)->update(['teleworking' => true]);

    try {
        expect(fn (): array => Commands::run(
            'migrate:rollback --database='.teleworkingMigrationConnection().' --step='.teleworkingMigrationSteps()
        ))->toThrow(RuntimeException::class, 'teletrabajo');

        // El dato sigue ahi: nada se ha borrado (regla dura 5).
        expect(teleworkingMigrationColumn())->not->toBeNull()
            ->and(DB::table('employees')->where('uuid', $uuid)->value('teleworking'))->toBeTrue();
    } finally {
        // Si hubiera migraciones posteriores, se habrian deshecho: se reaplican
        // para dejar el esquema como lo encontro la suite.
        teleworkingMigrationMigrate();
    }
})->group('RF-GP-01', 'RNF-D-04');
