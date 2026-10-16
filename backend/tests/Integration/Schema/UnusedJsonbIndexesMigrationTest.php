<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * Las dos migraciones que retiran los GIN sin uso (DB7; RNF-P-02, RNF-D-04).
 *
 * - Tras migrar, ni `scan_events` ni `audit_log` ni ninguna de sus particiones
 *   tiene un indice GIN.
 * - `down()` los reconstruye: el de `audit_log` VALIDO, con el indice de cada
 *   particion colgando de el (que es lo que hace el `ATTACH PARTITION`), y el de
 *   `scan_events` igual que se creo. Volver a migrar los retira otra vez.
 * - El `DROP INDEX` de `audit_log` no se queda esperando detras de quien tiene la
 *   tabla: con una lectura abierta en otra sesion, la migracion se rinde con
 *   `55P03` (`lock_timeout` de `limitLockWait()`) y no toca nada.
 *
 * Toda la manipulacion va por la conexion de migracion y no se consulta
 * `audit_log` por la conexion por defecto: la transaccion de `RefreshDatabase`
 * retendria bloqueos que el `rollback` tendria que esperar.
 */

uses(RefreshDatabase::class);

const UNUSED_JSONB_INDEXES_SCAN_EVENTS = '2026_10_16_100100_drop_unused_scan_events_client_meta_gin';

const UNUSED_JSONB_INDEXES_AUDIT_LOG = '2026_10_16_100200_drop_unused_audit_log_payload_gin';

afterEach(function (): void {
    DB::purge('unused_jsonb_blocker');
    // Idempotente: la base de pruebas es compartida y dejarla a medio migrar
    // convertiria un fallo de asercion en un fallo en cascada.
    Commands::run('migrate --database='.config()->string('database.migrations.connection'));
});

/**
 * Los indices GIN de `scan_events`, de `audit_log` y de sus particiones.
 *
 * @return list<string>
 */
function ginDeLasTablasDelRegistro(ConnectionInterface $connection): array
{
    /** @var list<object{indexname: string}> $rows */
    $rows = $connection->select(<<<'SQL'
        SELECT indexname
          FROM pg_indexes
         WHERE schemaname = 'public'
           AND (tablename = 'scan_events' OR tablename LIKE 'audit_log%')
           AND indexdef ILIKE '%USING gin%'
         ORDER BY indexname
        SQL);

    return array_map(static fn (object $row): string => $row->indexname, $rows);
}

/**
 * Pasos de `migrate:rollback` hasta deshacer `$migration`, incluida.
 */
function pasosHastaLaMigracionDeLosGin(ConnectionInterface $connection, string $migration): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();
    $position = array_search($migration, $applied, true);

    return $position === false ? 0 : $position + 1;
}

it('tras migrar no queda ningun GIN en scan_events, audit_log ni sus particiones', function (): void {
    $migrator = DB::connection(config()->string('database.migrations.connection'));

    expect(ginDeLasTablasDelRegistro($migrator))->toBe([]);
})->group('RNF-P-02');

it('down reconstruye los dos GIN, el de audit_log valido y con sus particiones; migrar los retira otra vez', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    $steps = pasosHastaLaMigracionDeLosGin($migrator, UNUSED_JSONB_INDEXES_SCAN_EVENTS);

    expect($steps)->toBeGreaterThan(0);

    [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

    expect($back)->toBe(0, $backOutput);

    /** @var list<object{relname: string}> $partitions */
    $partitions = $migrator->select(<<<'SQL'
        SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
         WHERE i.inhparent = 'audit_log'::regclass ORDER BY c.relname
        SQL);

    $esperados = ['audit_log_payload_gin', 'scan_events_client_meta_gin'];

    foreach ($partitions as $partition) {
        $esperados[] = $partition->relname.'_payload_idx';
    }

    sort($esperados);

    /** @var object{valid: bool, attached: int}|null $parent */
    $parent = $migrator->selectOne(<<<'SQL'
        SELECT i.indisvalid AS valid,
               (SELECT count(*) FROM pg_inherits h WHERE h.inhparent = i.indexrelid) AS attached
          FROM pg_index i
         WHERE i.indexrelid = 'audit_log_payload_gin'::regclass
        SQL);

    expect(ginDeLasTablasDelRegistro($migrator))->toBe($esperados)
        ->and($parent?->valid)->toBeTrue('audit_log_payload_gin no quedo valido: falta alguna particion por enganchar')
        ->and($parent?->attached)->toBe(\count($partitions));

    [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);

    expect($forward)->toBe(0, $forwardOutput)
        ->and(ginDeLasTablasDelRegistro($migrator))->toBe([]);
})->group('RNF-D-04', 'RNF-P-02');

it('el DROP INDEX de audit_log se rinde con 55P03 si otra sesion tiene la tabla, sin tocar nada', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='
        .pasosHastaLaMigracionDeLosGin($migrator, UNUSED_JSONB_INDEXES_AUDIT_LOG));

    expect($back)->toBe(0, $backOutput)
        ->and(ginDeLasTablasDelRegistro($migrator))->toContain('audit_log_payload_gin');

    config(['database.connections.unused_jsonb_blocker' => config()->array('database.connections.pgsql')]);
    $blocker = DB::connection('unused_jsonb_blocker');
    $blocker->beginTransaction();
    $state = 'sin error';

    try {
        // Una lectura basta: `ACCESS SHARE` choca con el `ACCESS EXCLUSIVE` del `DROP INDEX`.
        $blocker->select('SELECT 1 FROM audit_log LIMIT 1');

        try {
            Commands::run('migrate --database='.$name);
        } catch (QueryException $failure) {
            $state = (string) ($failure->errorInfo[0] ?? 'sin SQLSTATE');
        }
    } finally {
        $blocker->rollBack();
    }

    expect($state)->toBe('55P03')
        ->and(ginDeLasTablasDelRegistro($migrator))->toContain('audit_log_payload_gin')
        ->and($migrator->table('migrations')->where('migration', UNUSED_JSONB_INDEXES_AUDIT_LOG)->exists())->toBeFalse();

    [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);

    expect($forward)->toBe(0, $forwardOutput)
        ->and(ginDeLasTablasDelRegistro($migrator))->toBe([]);
})->group('RNF-D-04');
