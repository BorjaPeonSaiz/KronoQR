<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * La migracion que crea `audit_log_create_partition` sabe volver atras
 * (ADR-042, RS-07, skill `migracion-segura`).
 *
 * LO QUE SE AFIRMA, mas alla de que la funcion desaparezca:
 *
 * - que `down()` **no suelta ninguna particion**. Son datos: revertir quita una
 *   capacidad, nunca registro;
 * - que volver a migrar la recrea **con los mismos permisos y la misma
 *   configuracion**, no con los de por defecto (que darian `EXECUTE` a `PUBLIC`);
 * - que la funcion de purga recupera su `search_path` anterior al revertir y
 *   vuelve al alineado al migrar.
 *
 * Toda la manipulacion va por la conexion de migracion y no se consulta
 * `audit_log` por la conexion por defecto: la transaccion de `RefreshDatabase`
 * retendria bloqueos que el `rollback` tendria que esperar.
 */

uses(RefreshDatabase::class);

const AUDIT_LOG_PARTITION_FUNCTION_MIGRATION = '2026_09_29_100000_audit_log_partition_function';

it('down quita la funcion, devuelve el search_path de la purga y conserva las particiones; migrar la recrea igual', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    $before = auditLogPartitionFunctionCatalog($migrator);
    $partitionsBefore = auditLogPartitionFunctionPartitions($migrator);

    expect($before)->not->toBeNull()
        ->and(auditLogPartitionFunctionPurgeConfig($migrator))->toContain('search_path='.AuditLogSchema::DEFINER_SEARCH_PATH);

    try {
        $steps = auditLogPartitionFunctionStepsBack($migrator, AUDIT_LOG_PARTITION_FUNCTION_MIGRATION);

        expect($steps)->toBeGreaterThan(0);

        [$exitCode, $output] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($exitCode)->toBe(0, $output)
            ->and(auditLogPartitionFunctionCatalog($migrator))->toBeNull()
            // Ninguna particion se va con la funcion.
            ->and(auditLogPartitionFunctionPartitions($migrator))->toBe($partitionsBefore)
            ->and(auditLogPartitionFunctionPurgeConfig($migrator))
            ->toContain('search_path='.AuditLogSchema::LEGACY_DROP_FUNCTION_SEARCH_PATH);

        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0, $forwardOutput);
    } finally {
        // Idempotente: la base de pruebas es compartida y dejarla sin la
        // funcion convertiria un fallo de asercion en un fallo en cascada.
        Commands::run('migrate --database='.$name);
    }

    expect(auditLogPartitionFunctionCatalog($migrator))->toBe($before)
        ->and(auditLogPartitionFunctionPartitions($migrator))->toBe($partitionsBefore)
        ->and(auditLogPartitionFunctionPurgeConfig($migrator))->toContain('search_path='.AuditLogSchema::DEFINER_SEARCH_PATH);
})->group('RS-07');

/**
 * `proacl`, `proconfig`, propietario y `prosecdef` de la funcion, o `null` si no
 * existe.
 *
 * @return array{acl: string, config: string, owner: string, secdef: bool}|null
 */
function auditLogPartitionFunctionCatalog(ConnectionInterface $connection): ?array
{
    /** @var object{acl: string|null, config: string|null, owner: string, secdef: bool}|null $row */
    $row = $connection->selectOne(<<<'SQL'
        SELECT array_to_json(ARRAY(SELECT a::text FROM unnest(p.proacl) a ORDER BY 1))::text AS acl,
               array_to_json(p.proconfig)::text AS config,
               pg_get_userbyid(p.proowner) AS owner,
               p.prosecdef AS secdef
          FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname = 'public' AND p.proname = ?
    SQL, [AuditLogSchema::CREATE_FUNCTION]);

    if ($row === null) {
        return null;
    }

    return [
        'acl' => (string) $row->acl,
        'config' => (string) $row->config,
        'owner' => $row->owner,
        'secdef' => $row->secdef,
    ];
}

/**
 * @return list<string>
 */
function auditLogPartitionFunctionPartitions(ConnectionInterface $connection): array
{
    /** @var list<object{relname: string}> $rows */
    $rows = $connection->select(<<<'SQL'
        SELECT c.relname
          FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
         WHERE i.inhparent = 'public.audit_log'::regclass
         ORDER BY c.relname
    SQL);

    return array_map(static fn (object $row): string => $row->relname, $rows);
}

/**
 * @return list<string>
 */
function auditLogPartitionFunctionPurgeConfig(ConnectionInterface $connection): array
{
    /** @var object{config: string|null}|null $row */
    $row = $connection->selectOne(
        'SELECT array_to_json(proconfig)::text AS config FROM pg_proc WHERE oid = ?::regprocedure',
        ['public.'.AuditLogSchema::DROP_FUNCTION.'(integer)'],
    );

    /** @var list<string> $config */
    $config = json_decode($row === null ? '[]' : ($row->config ?? '[]'), true, 512, JSON_THROW_ON_ERROR);

    return $config;
}

/**
 * Cuantos pasos de `migrate:rollback` deshacen la migracion indicada. Hoy es la
 * ultima, pero la prueba no debe romperse cuando deje de serlo.
 */
function auditLogPartitionFunctionStepsBack(ConnectionInterface $connection, string $migration): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === $migration) {
            return $steps;
        }
    }

    return 0;
}
