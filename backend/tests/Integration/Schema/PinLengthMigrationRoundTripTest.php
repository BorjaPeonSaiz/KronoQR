<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `employees.pin_length` (RF-ID-09, ADR-050): la migracion vuelve atras y se
 * reaplica rellenando con seis los PIN existentes.
 *
 * Vivia en `tests/Integration/Workforce/PinLengthMigrationTest.php`, ejecutando
 * `down()` y `up()` DENTRO de la transaccion de la prueba. Desde el bloque 13 de
 * la 2.2.0 la migracion es no transaccional —el `VALIDATE` de sus `CHECK` va
 * despues del `COMMIT`, hallazgo DB3— y `validateConstraint()` se niega a correr
 * dentro de una transaccion, asi que se prueba como las demas de
 * `tests/Integration/Schema/`: con `migrate:rollback --path` sobre el rol de
 * migracion y `CommittedDatabase`, que es lo que se ejecuta en un servidor.
 */

uses(CommittedDatabase::class);

const PIN_LENGTH_ROUND_TRIP_MIGRATION = '2026_10_06_100000_add_pin_length_to_employees_table';

function pinLengthRoundTripConnection(): string
{
    return config()->string('database.migrations.connection');
}

/** Cuantos pasos deshacer para alcanzar la migracion, contando desde la ultima. */
function pinLengthRoundTripSteps(): int
{
    /** @var list<string> $applied */
    $applied = DB::connection(pinLengthRoundTripConnection())->table('migrations')
        ->orderBy('id')
        ->pluck('migration')
        ->map(static fn (mixed $name): string => (string) $name) // @phpstan-ignore-line cast.string (`pluck` devuelve `mixed`; la columna es `varchar` y el nombre de una migracion siempre es texto)
        ->values()
        ->all();

    $position = array_search(PIN_LENGTH_ROUND_TRIP_MIGRATION, $applied, true);

    expect($position)->toBeInt(PIN_LENGTH_ROUND_TRIP_MIGRATION.' no esta aplicada.');

    /** @var int $position */
    return \count($applied) - $position;
}

function pinLengthRoundTripHasColumn(): bool
{
    return DB::connection(pinLengthRoundTripConnection())
        ->table('information_schema.columns')
        ->where('table_name', 'employees')
        ->where('column_name', 'pin_length')
        ->exists();
}

it('rellena con seis los PIN existentes y sabe volver atras', function (): void {
    $name = pinLengthRoundTripConnection();
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la migracion'));

    DB::table('employees')->where('uuid', $uuid)->update([
        'pin_hash' => '$2y$04$abcdefghijklmnopqrstuv',
        'pin_issued_at' => '2026-10-06T09:00:00Z',
        'pin_length' => 6,
    ]);

    try {
        // `--path` acota la vuelta atras a ESTA migracion: las posteriores no
        // nombran `pin_length` y se quedan como estan.
        [$rolledBack, $output] = Commands::run(
            'migrate:rollback --database='.$name.' --step='.pinLengthRoundTripSteps()
            .' --path=database/migrations/'.PIN_LENGTH_ROUND_TRIP_MIGRATION.'.php'
        );

        expect($rolledBack)->toBe(0, $output)
            ->and(pinLengthRoundTripHasColumn())->toBeFalse();

        [$migrated, $migrateOutput] = Commands::run('migrate --database='.$name);

        expect($migrated)->toBe(0, $migrateOutput);
    } finally {
        // Idempotente: la base de pruebas es compartida y quedarse sin la columna
        // convertiria un fallo de asercion en un fallo en cascada.
        Commands::run('migrate --database='.$name);
    }

    $validadas = DB::connection($name)->table('pg_constraint')
        ->whereIn('conname', ['employees_chk_pin_length_admissible', 'employees_chk_pin_length_with_hash'])
        ->where('convalidated', true)
        ->count();

    expect(pinLengthRoundTripHasColumn())->toBeTrue()
        ->and(DB::table('employees')->where('uuid', $uuid)->value('pin_length'))->toBe(6)
        // Las dos restricciones, presentes Y validadas tras el `VALIDATE` fuera
        // de transaccion.
        ->and($validadas)->toBe(2);
})->group('RF-ID-09', 'RNF-D-04');
