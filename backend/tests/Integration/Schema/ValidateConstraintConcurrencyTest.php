<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\LockLimitedMigration;

/*
 * Una restriccion nueva se valida SIN cerrar la tabla a las escrituras
 * (RNF-D-04; R5-BD-01 y DB3 de la verificacion; skill `/migracion-segura`).
 *
 * El patron es `ADD CONSTRAINT … NOT VALID` en una transaccion corta y
 * `VALIDATE CONSTRAINT` en otra (`LimitsMigrationLocks::validateConstraint()`).
 * Lo que aqui se ve en `pg_locks`, con dos sesiones de verdad:
 *
 * 1. El `VALIDATE` toma `SHARE UPDATE EXCLUSIVE` y nada mas fuerte, y mientras
 *    lo tiene otra sesion inserta sin esperar.
 * 2. `validateConstraint()` termina aunque otra sesion tenga un `INSERT` sin
 *    confirmar en la tabla.
 * 3. El control, que es R5-BD-01: el `ADD CONSTRAINT` toma `ACCESS EXCLUSIVE`, y
 *    si el `VALIDATE` fuera en su misma transaccion lo mantendria todo el
 *    recorrido: un `INSERT` concurrente se rinde con `55P03`.
 *
 * Sobre una tabla propia de la prueba, creada y borrada aqui con el rol de
 * migracion, para no tocar el esquema del producto.
 */

const VALIDATE_CONCURRENCY_TABLE = 'validate_concurrency_probe';

const VALIDATE_CONCURRENCY_CONSTRAINT = 'validate_concurrency_probe_value_positive';

/** La segunda sesion: el rol de migracion por otra conexion. */
function sesionEscritoraDeLaValidacion(): Connection
{
    config(['database.connections.validate_concurrency_writer' => config()->array(
        'database.connections.'.config()->string('database.migrations.connection'),
    )]);

    return DB::connection('validate_concurrency_writer');
}

function migradorDeLaValidacion(): Connection
{
    return DB::connection(config()->string('database.migrations.connection'));
}

/**
 * Los modos de bloqueo CONCEDIDOS a la sesion `$session` sobre la tabla de la
 * prueba, vistos desde otra sesion.
 *
 * @return list<string>
 */
function bloqueosSobreLaTablaDeLaValidacion(Connection $observer, Connection $session): array
{
    /** @var object{pid: int} $pid */
    $pid = $session->selectOne('SELECT pg_backend_pid() AS pid');

    /** @var list<object{mode: string}> $rows */
    $rows = $observer->select(
        'SELECT mode FROM pg_locks WHERE granted AND relation = ?::regclass AND pid = ? ORDER BY mode',
        [VALIDATE_CONCURRENCY_TABLE, $pid->pid],
    );

    return array_map(static fn (object $row): string => $row->mode, $rows);
}

/** `ok` si la sesion inserta sin esperar mas de medio segundo, o el SQLSTATE. */
function insertarDuranteLaValidacion(Connection $writer): string
{
    $writer->beginTransaction();

    try {
        $writer->statement("SET LOCAL lock_timeout = '500ms'");
        $writer->insert('INSERT INTO '.VALIDATE_CONCURRENCY_TABLE.' (value) VALUES (7)');

        return 'ok';
    } catch (QueryException $failure) {
        return (string) ($failure->errorInfo[0] ?? 'sin SQLSTATE');
    } finally {
        $writer->rollBack();
    }
}

beforeEach(function (): void {
    $migrator = migradorDeLaValidacion();
    $migrator->statement('DROP TABLE IF EXISTS '.VALIDATE_CONCURRENCY_TABLE);
    $migrator->statement('CREATE TABLE '.VALIDATE_CONCURRENCY_TABLE.' (id BIGSERIAL PRIMARY KEY, value INTEGER NOT NULL)');
    $migrator->statement('INSERT INTO '.VALIDATE_CONCURRENCY_TABLE.' (value) SELECT g FROM generate_series(1, 1000) AS g');
});

afterEach(function (): void {
    DB::purge('validate_concurrency_writer');

    $migrator = migradorDeLaValidacion();

    while ($migrator->transactionLevel() > 0) {
        $migrator->rollBack();
    }

    $migrator->statement('DROP TABLE IF EXISTS '.VALIDATE_CONCURRENCY_TABLE);
});

it('el VALIDATE toma SHARE UPDATE EXCLUSIVE y nada mas, y mientras lo tiene otra sesion inserta', function (): void {
    $migrator = migradorDeLaValidacion();
    $writer = sesionEscritoraDeLaValidacion();

    // Paso 1, ya confirmado: la restriccion existe sin validar.
    $migrator->transaction(static fn (): bool => $migrator->statement(
        'ALTER TABLE '.VALIDATE_CONCURRENCY_TABLE.' ADD CONSTRAINT '.VALIDATE_CONCURRENCY_CONSTRAINT.' CHECK (value > 0) NOT VALID'
    ));

    // Paso 2 a mano y SIN confirmar, para poder mirar sus bloqueos mientras los tiene.
    $migrator->beginTransaction();
    $migrator->statement('ALTER TABLE '.VALIDATE_CONCURRENCY_TABLE.' VALIDATE CONSTRAINT '.VALIDATE_CONCURRENCY_CONSTRAINT);

    $modos = bloqueosSobreLaTablaDeLaValidacion($writer, $migrator);
    $insercion = insertarDuranteLaValidacion($writer);

    $migrator->rollBack();

    expect($modos)->toContain('ShareUpdateExclusiveLock')
        ->and($modos)->not->toContain('AccessExclusiveLock')
        ->and($modos)->not->toContain('ShareLock')
        ->and($modos)->not->toContain('ExclusiveLock')
        ->and($insercion)->toBe('ok');
})->group('RNF-D-04');

it('validateConstraint() termina con un INSERT sin confirmar de otra sesion en la tabla', function (): void {
    $migrator = migradorDeLaValidacion();
    $writer = sesionEscritoraDeLaValidacion();

    $migrator->transaction(static fn (): bool => $migrator->statement(
        'ALTER TABLE '.VALIDATE_CONCURRENCY_TABLE.' ADD CONSTRAINT '.VALIDATE_CONCURRENCY_CONSTRAINT.' CHECK (value > 0) NOT VALID'
    ));

    $writer->beginTransaction();

    try {
        $writer->insert('INSERT INTO '.VALIDATE_CONCURRENCY_TABLE.' (value) VALUES (9)');

        expect(bloqueosSobreLaTablaDeLaValidacion($migrator, $writer))->toContain('RowExclusiveLock');

        // Con el `lock_timeout` de 3 s del ayudante: si esperase al INSERT, lanzaria 55P03.
        new LockLimitedMigration(config()->string('database.migrations.connection'))
            ->validate(VALIDATE_CONCURRENCY_TABLE, VALIDATE_CONCURRENCY_CONSTRAINT);
    } finally {
        $writer->rollBack();
    }

    expect($migrator->table('pg_constraint')->where('conname', VALIDATE_CONCURRENCY_CONSTRAINT)->value('convalidated'))->toBeTrue();
})->group('RNF-D-04');

it('el control (R5-BD-01): el ADD CONSTRAINT toma ACCESS EXCLUSIVE y un INSERT concurrente se rinde con 55P03', function (): void {
    $migrator = migradorDeLaValidacion();
    $writer = sesionEscritoraDeLaValidacion();

    // Lo que hacia la migracion antigua: ADD y VALIDATE en la misma transaccion.
    $migrator->beginTransaction();
    $migrator->statement(
        'ALTER TABLE '.VALIDATE_CONCURRENCY_TABLE.' ADD CONSTRAINT '.VALIDATE_CONCURRENCY_CONSTRAINT.' CHECK (value > 0) NOT VALID'
    );
    $migrator->statement('ALTER TABLE '.VALIDATE_CONCURRENCY_TABLE.' VALIDATE CONSTRAINT '.VALIDATE_CONCURRENCY_CONSTRAINT);

    $modos = bloqueosSobreLaTablaDeLaValidacion($writer, $migrator);
    $insercion = insertarDuranteLaValidacion($writer);

    $migrator->rollBack();

    expect($modos)->toContain('AccessExclusiveLock')
        ->and($insercion)->toBe('55P03');
})->group('RNF-D-04');
