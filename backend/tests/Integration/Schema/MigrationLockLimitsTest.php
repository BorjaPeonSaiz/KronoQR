<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\LockLimitedMigration;

/*
 * `LimitsMigrationLocks`: los topes de una migracion no sobreviven a la
 * migracion (hallazgos DB4 y DB3 de la 2.2.0, R5-BD-01, R5-BD-02, RNF-D-04).
 *
 * Hasta la 2.2.0 `limitLockWait()` usaba `SET` de sesion: el `statement_timeout
 * = 30s` de una migracion lo heredaba la siguiente del mismo `artisan migrate`,
 * incluida la de un `CREATE INDEX CONCURRENTLY`, que a los 30 s queda `INVALID`.
 * Aqui se comprueba sobre PostgreSQL de verdad, mirando `SHOW` en la MISMA
 * conexion, que es donde se notaba.
 *
 * **Sin `RefreshDatabase` a proposito**: su transaccion envolvente haria que
 * `transactionLevel()` nunca fuera cero, y lo que se prueba es justo la
 * diferencia entre estar dentro y fuera de una transaccion. Nada de esto
 * escribe filas; la unica migracion real que se deshace se reaplica al final.
 */

const MIGRATION_LOCK_LIMITS_REAL = '2026_10_07_100000_add_temporary_password_to_users_table';

/** @return array{lock_timeout: string, statement_timeout: string} */
function topesDeLaSesion(Connection $connection): array
{
    /** @var list<object{lock: string, statement: string}> $rows */
    $rows = $connection->select(
        "SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement"
    );

    return ['lock_timeout' => $rows[0]->lock, 'statement_timeout' => $rows[0]->statement];
}

/** Valores de sesion que no coinciden con ninguno de los del trait. */
function fijarTopesDeSesion(Connection $connection): void
{
    $connection->statement("SET lock_timeout = '7s'");
    $connection->statement("SET statement_timeout = '45s'");
}

function restaurarTopesDeSesion(Connection $connection): void
{
    $connection->statement('RESET lock_timeout');
    $connection->statement('RESET statement_timeout');
}

it('se niega a limitar fuera de una transaccion en vez de dejar la migracion sin topes', function (): void {
    expect(DB::connection()->transactionLevel())->toBe(0);

    expect(static fn () => new LockLimitedMigration()->limit())
        ->toThrow(LogicException::class, 'withLockWaitOnly()');
})->group('RNF-D-04');

it('aplica los dos topes solo a la transaccion de la migracion', function (): void {
    $connection = DB::connection();
    fijarTopesDeSesion($connection);

    try {
        $migration = new LockLimitedMigration;
        $dentro = [];

        // Lo mismo que `Migrator::runMigration()` con `$withinTransaction = true`.
        $connection->transaction(static function () use ($migration, $connection, &$dentro): bool {
            $migration->limit();
            $dentro = topesDeLaSesion($connection);

            return true;
        });

        expect($dentro)->toBe(['lock_timeout' => '3s', 'statement_timeout' => '30s'])
            ->and(topesDeLaSesion($connection))->toBe(['lock_timeout' => '7s', 'statement_timeout' => '45s']);
    } finally {
        restaurarTopesDeSesion($connection);
    }
})->group('RNF-D-04');

it('no deja los topes en la sesion tras una migracion real del migrador', function (): void {
    // La cadena de verdad: `migrate:rollback` y `migrate` en este mismo proceso
    // usan la MISMA conexion `pgsql_migrator` que se mira despues. Es justo como
    // se heredaba el `statement_timeout` de una migracion a la siguiente.
    $name = config()->string('database.migrations.connection');
    $connection = DB::connection($name);

    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderBy('id')->pluck('migration')->map(
        static fn (mixed $migration): string => (string) $migration // @phpstan-ignore-line cast.string (`pluck` devuelve `mixed`; la columna es `varchar` y el nombre de una migracion siempre es texto)
    )->values()->all();
    $position = array_search(MIGRATION_LOCK_LIMITS_REAL, $applied, true);

    expect($position)->toBeInt(MIGRATION_LOCK_LIMITS_REAL.' no esta aplicada.');

    /** @var int $position */
    fijarTopesDeSesion($connection);

    try {
        [$rolledBack, $output] = Commands::run(
            'migrate:rollback --database='.$name.' --step='.(\count($applied) - $position)
            .' --path=database/migrations/'.MIGRATION_LOCK_LIMITS_REAL.'.php'
        );

        expect($rolledBack)->toBe(0, $output)
            ->and(topesDeLaSesion($connection))->toBe(['lock_timeout' => '7s', 'statement_timeout' => '45s']);

        [$migrated, $migrateOutput] = Commands::run('migrate --database='.$name);

        expect($migrated)->toBe(0, $migrateOutput)
            ->and(topesDeLaSesion($connection))->toBe(['lock_timeout' => '7s', 'statement_timeout' => '45s']);
    } finally {
        Commands::run('migrate --database='.$name);
        restaurarTopesDeSesion($connection);
    }
})->group('RNF-D-04');

it('ejecuta sin tope de duracion y devuelve la sesion a como estaba aunque el closure lance', function (): void {
    $connection = DB::connection();
    fijarTopesDeSesion($connection);

    try {
        $dentro = [];

        expect(static function () use ($connection, &$dentro): void {
            new LockLimitedMigration()->lockWaitOnly(static function () use ($connection, &$dentro): never {
                $dentro = topesDeLaSesion($connection);

                throw new RuntimeException('falla a mitad');
            });
        })->toThrow(RuntimeException::class, 'falla a mitad');

        expect($dentro)->toBe(['lock_timeout' => '3s', 'statement_timeout' => '0'])
            ->and(topesDeLaSesion($connection))->toBe(['lock_timeout' => '7s', 'statement_timeout' => '45s']);

        // Y por el camino feliz, lo mismo.
        expect(new LockLimitedMigration()->lockWaitOnly(static fn (): string => 'hecho'))->toBe('hecho')
            ->and(topesDeLaSesion($connection))->toBe(['lock_timeout' => '7s', 'statement_timeout' => '45s']);
    } finally {
        restaurarTopesDeSesion($connection);
    }
})->group('RNF-D-04');

it('se niega a validar una restriccion dentro de una transaccion', function (): void {
    // Dentro, el `ACCESS EXCLUSIVE` del `ADD CONSTRAINT ... NOT VALID` duraria
    // todo el recorrido del `VALIDATE`: el patron en dos pasos no serviria de
    // nada (hallazgo DB3).
    $migration = new LockLimitedMigration;

    expect(static fn () => DB::transaction(static fn () => $migration->validate('incidents', 'incidents_chk_type')))
        ->toThrow(LogicException::class, '$withinTransaction = false')
        ->and(static fn () => DB::transaction(static fn () => $migration->lockWaitOnly(static fn (): bool => true)))
        ->toThrow(LogicException::class, '$withinTransaction = false');
})->group('RNF-D-04');

it('valida fuera de transaccion y solo admite identificadores simples', function (): void {
    // `incidents_chk_type` ya es valida: repetir el `VALIDATE` no hace nada, que
    // es lo que hace seguro reintentarlo tras una interrupcion. Con el rol de
    // migracion: `VALIDATE` exige ser el dueño de la tabla (regla dura 6).
    $name = config()->string('database.migrations.connection');
    $migration = new LockLimitedMigration($name);

    $migration->validate('incidents', 'incidents_chk_type');

    expect(DB::connection($name)->table('pg_constraint')->where('conname', 'incidents_chk_type')->value('convalidated'))->toBeTrue()
        ->and(static fn () => $migration->validate('incidents; DROP TABLE incidents', 'incidents_chk_type'))
        ->toThrow(LogicException::class, 'Identificador no admitido');
})->group('RNF-D-04');
