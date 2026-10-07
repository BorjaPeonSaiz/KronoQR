<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\LockLimitedMigration;

/*
 * `LimitsMigrationLocks::createIndexConcurrently()`: un reintento no da por
 * bueno un indice `INVALID` (revision del bloque 13 de la 2.2.0, RNF-D-04).
 *
 * `CREATE INDEX CONCURRENTLY IF NOT EXISTS` salta cualquier indice con ese
 * nombre, tambien el `INVALID` que deja una construccion interrumpida —el
 * `lock_timeout` de la espera final a las transacciones abiertas basta—. La
 * migracion quedaba anotada y el indice se mantenia en cada escritura sin servir
 * a ninguna lectura.
 *
 * **El `INVALID` se fabrica de verdad**: un `CREATE UNIQUE INDEX CONCURRENTLY`
 * sobre una columna con duplicados falla en la fase de validacion y PostgreSQL
 * deja el indice en el catalogo con `indisvalid = false`, que es exactamente el
 * estado de una construccion interrumpida. Sobre una tabla de sondeo propia y
 * con el rol de migracion —`CONCURRENTLY` exige ser el dueño, y sobre una tabla
 * temporal PostgreSQL lo ejecuta sin concurrencia y no deja nada `INVALID`—.
 *
 * **Sin `RefreshDatabase`**, como `MigrationLockLimitsTest`: `CONCURRENTLY` no
 * se ejecuta dentro de una transaccion. La tabla de sondeo se borra al terminar.
 */

const CONCURRENT_INDEX_HELPER_TABLE = 'concurrent_index_helper_probe';

const CONCURRENT_INDEX_HELPER_INDEX = 'concurrent_index_helper_probe_value_index';

function concurrentIndexHelperConnection(): Connection
{
    return DB::connection(config()->string('database.migrations.connection'));
}

/**
 * `indisvalid` e `indisunique` del indice de sondeo, o `null` si no existe.
 *
 * @return array{valid: bool, unique: bool}|null
 */
function concurrentIndexHelperState(): ?array
{
    /** @var list<object{valid: bool, unique: bool}> $rows */
    $rows = concurrentIndexHelperConnection()->select(<<<'SQL'
        SELECT i.indisvalid AS valid, i.indisunique AS unique
          FROM pg_index i
          JOIN pg_class c ON c.oid = i.indexrelid
         WHERE c.relname = ?
        SQL, [CONCURRENT_INDEX_HELPER_INDEX]);

    return isset($rows[0]) ? ['valid' => $rows[0]->valid, 'unique' => $rows[0]->unique] : null;
}

/** La tabla de sondeo, con un valor repetido para que un indice UNICO no pueda validarse. */
function concurrentIndexHelperProbe(): void
{
    $connection = concurrentIndexHelperConnection();

    $connection->statement('DROP TABLE IF EXISTS '.CONCURRENT_INDEX_HELPER_TABLE);
    $connection->statement('CREATE TABLE '.CONCURRENT_INDEX_HELPER_TABLE.' (id bigint PRIMARY KEY, value integer NOT NULL)');
    $connection->statement('INSERT INTO '.CONCURRENT_INDEX_HELPER_TABLE.' (id, value) SELECT g, g % 10 FROM generate_series(1, 100) AS g');
}

function concurrentIndexHelperCleanUp(): void
{
    concurrentIndexHelperConnection()->statement('DROP TABLE IF EXISTS '.CONCURRENT_INDEX_HELPER_TABLE);
}

it('sustituye un indice INVALID con el mismo nombre por uno valido', function (): void {
    concurrentIndexHelperProbe();

    try {
        // La construccion que falla a mitad: los duplicados de `value` la
        // rompen en la validacion y el indice se queda `INVALID` en el catalogo.
        expect(static fn () => concurrentIndexHelperConnection()->statement(
            'CREATE UNIQUE INDEX CONCURRENTLY '.CONCURRENT_INDEX_HELPER_INDEX.' ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)'
        ))->toThrow(QueryException::class);

        expect(concurrentIndexHelperState())->toBe(['valid' => false, 'unique' => true]);

        // Lo que hacia la migracion hasta la 2.2.0: `IF NOT EXISTS` lo salta y
        // el indice sigue `INVALID`, sin error.
        concurrentIndexHelperConnection()->statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.CONCURRENT_INDEX_HELPER_INDEX.' ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)'
        );

        expect(concurrentIndexHelperState())->toBe(['valid' => false, 'unique' => true]);

        // El ayudante lo borra y construye el de la definicion: valido, y no
        // unico, que es como se sabe que NO es el de antes.
        new LockLimitedMigration(config()->string('database.migrations.connection'))
            ->createIndex(CONCURRENT_INDEX_HELPER_INDEX, 'ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)');

        expect(concurrentIndexHelperState())->toBe(['valid' => true, 'unique' => false]);
    } finally {
        concurrentIndexHelperCleanUp();
    }
})->group('RNF-D-04');

it('es idempotente y su vuelta atras borra el indice', function (): void {
    concurrentIndexHelperProbe();
    $migration = new LockLimitedMigration(config()->string('database.migrations.connection'));

    try {
        $migration->createIndex(CONCURRENT_INDEX_HELPER_INDEX, 'ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)');
        $migration->createIndex(CONCURRENT_INDEX_HELPER_INDEX, 'ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)');

        expect(concurrentIndexHelperState())->toBe(['valid' => true, 'unique' => false]);

        $migration->dropIndex(CONCURRENT_INDEX_HELPER_INDEX);
        $migration->dropIndex(CONCURRENT_INDEX_HELPER_INDEX);

        expect(concurrentIndexHelperState())->toBeNull();
    } finally {
        concurrentIndexHelperCleanUp();
    }
})->group('RNF-D-04');

it('deja el INVALID de una construccion que falla para que el reintento lo sustituya', function (): void {
    // Una expresion que divide por cero falla al calcular las entradas, a mitad
    // de la construccion: lanza la propia sentencia, la migracion no se anota
    // y el indice queda `INVALID`. El siguiente intento empieza borrandolo.
    concurrentIndexHelperProbe();
    $migration = new LockLimitedMigration(config()->string('database.migrations.connection'));

    try {
        expect(static fn () => $migration->createIndex(CONCURRENT_INDEX_HELPER_INDEX, 'ON '.CONCURRENT_INDEX_HELPER_TABLE.' ((value / 0))'))
            ->toThrow(QueryException::class);

        expect(concurrentIndexHelperState())->toBe(['valid' => false, 'unique' => false]);

        // El reintento con la definicion buena lo sustituye.
        $migration->createIndex(CONCURRENT_INDEX_HELPER_INDEX, 'ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)');

        expect(concurrentIndexHelperState())->toBe(['valid' => true, 'unique' => false]);
    } finally {
        concurrentIndexHelperCleanUp();
    }
})->group('RNF-D-04');

it('lanza si tras construir no hay un indice valido con ese nombre', function (): void {
    // `IF NOT EXISTS` tambien salta en silencio si el nombre lo ocupa otra
    // relacion: aqui, la propia tabla de sondeo. No hay indice que comprobar y
    // el ayudante lo dice en vez de anotar la migracion.
    concurrentIndexHelperProbe();
    $migration = new LockLimitedMigration(config()->string('database.migrations.connection'));

    try {
        expect(static fn () => $migration->createIndex(CONCURRENT_INDEX_HELPER_TABLE, 'ON '.CONCURRENT_INDEX_HELPER_TABLE.' (value)'))
            ->toThrow(RuntimeException::class, 'no ha quedado valido');
    } finally {
        concurrentIndexHelperCleanUp();
    }
})->group('RNF-D-04');

it('rechaza identificadores y definiciones que no son de una migracion', function (): void {
    $migration = new LockLimitedMigration(config()->string('database.migrations.connection'));
    // Sobre la conexion por defecto, que es la de `DB::transaction()`: la
    // comprobacion mira la transaccion de la conexion de la migracion.
    $default = new LockLimitedMigration;

    expect(static fn () => $migration->createIndex('x_index; DROP TABLE incidents', 'ON incidents (status)'))
        ->toThrow(LogicException::class, 'Identificador no admitido')
        ->and(static fn () => $migration->createIndex('x_index', '; DROP TABLE incidents'))
        ->toThrow(LogicException::class, 'ON tabla')
        ->and(static fn () => $migration->dropIndex('x_index; DROP TABLE incidents'))
        ->toThrow(LogicException::class, 'Identificador no admitido')
        ->and(static fn () => DB::transaction(static fn () => $default->createIndex('x_index', 'ON incidents (status)')))
        ->toThrow(LogicException::class, '$withinTransaction = false')
        ->and(static fn () => DB::transaction(static fn () => $default->dropIndex('x_index')))
        ->toThrow(LogicException::class, '$withinTransaction = false');
})->group('RNF-D-04');
