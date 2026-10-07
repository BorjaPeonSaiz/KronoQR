<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El PLAN de una consulta que emite un adaptador de verdad, para las pruebas
 * de uso de indices (RNF-P-02).
 *
 * Nacio dentro de `tests/Integration/Attendance/ScanLogIndexUsageTest.php` y se
 * saco aqui cuando el diario de jornadas, la bandeja de incidencias y la
 * exportacion legal necesitaron lo mismo (2.2.0, bloque 13). Las reglas que
 * fijaba aquel fichero siguen valiendo para todos:
 *
 * - **El SQL no se transcribe, se captura.** Se escucha la conexion, se llama al
 *   puerto real y se hace `EXPLAIN` sobre lo que emitio el adaptador. Un SQL
 *   copiado a mano se queda viejo en cuanto alguien toca el adaptador.
 * - **Se recorre el arbol y no se buscan subcadenas.** El JSON del plan contiene
 *   a la vez «Seq Scan» y el nombre de una tabla en cuanto OTRA tabla pequeña se
 *   recorre entera, que es correcto; con `str_contains` la prueba fallaba o no
 *   segun cuantas filas hubiera sembradas.
 *
 * (El mismo recorrido vive en `load-tests/k6/support.php` para la verificacion
 * posterior a la prueba de carga. No se comparte a proposito: son dos arboles
 * distintos del repositorio y atarlos por ocho lineas costaria mas de lo que
 * ahorra.)
 */
final class QueryPlans
{
    /**
     * `ANALYZE` de verdad: con el rol de migracion, y comprobando que ha dejado
     * estadisticas.
     *
     * **Con el rol de la aplicacion no hace nada**, y en silencio: PostgreSQL
     * solo deja analizar al dueño de la tabla (o a quien tenga `MAINTAIN`), y a
     * cualquier otro le responde con un `WARNING` y sigue. El planificador
     * planifica entonces con `reltuples = -1` —tabla nunca analizada— y una
     * prueba de planes mide heuristicas en vez de estadisticas. Por eso las
     * pruebas que lo usan siembran con {@see CommittedDatabase}: la conexion del
     * rol de migracion no ve lo que la transaccion de `RefreshDatabase` no ha
     * confirmado.
     */
    public static function analyze(string ...$tables): void
    {
        $connection = DB::connection(config()->string('database.migrations.connection'));

        foreach ($tables as $table) {
            if (preg_match('/\A[a-z_]+\z/', $table) !== 1) {
                throw new RuntimeException('Tabla no admitida: '.$table);
            }

            $connection->statement('ANALYZE '.$table);

            /** @var mixed $tuples */
            $tuples = $connection->table('pg_class')->where('relname', $table)->value('reltuples');

            if (! is_numeric($tuples) || (float) $tuples <= 0) {
                throw new RuntimeException('ANALYZE '.$table.' no dejo estadisticas: el plan mediria heuristicas.');
            }
        }
    }

    /**
     * Las consultas que emite `$call` y que cumplen `$keep`, con sus bindings.
     *
     * @param  Closure(): mixed  $call
     * @param  Closure(string): bool  $keep  recibe el SQL en minusculas
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    public static function capture(Closure $call, Closure $keep): array
    {
        $captured = [];

        DB::listen(static function (QueryExecuted $query) use (&$captured, $keep): void {
            if ($keep(strtolower($query->sql))) {
                $captured[] = ['sql' => $query->sql, 'bindings' => array_values($query->bindings)];
            }
        });

        $call();

        return $captured;
    }

    /**
     * Las `SELECT` (o `WITH ... SELECT`) emitidas por `$call` que nombran `$table`.
     *
     * @param  Closure(): mixed  $call
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    public static function selectsOn(string $table, Closure $call): array
    {
        return self::capture($call, static fn (string $sql): bool => str_contains($sql, $table)
            && (str_starts_with(ltrim($sql), 'select') || str_starts_with(ltrim($sql), 'with')));
    }

    /**
     * El plan de la consulta, ya aplanado en nodos.
     *
     * @param  array{sql: string, bindings: list<mixed>}  $query
     * @return list<array<string, mixed>>
     */
    public static function nodes(array $query): array
    {
        $root = self::root('EXPLAIN (FORMAT JSON) ', $query);

        return $root === null ? [] : self::flatten($root);
    }

    /**
     * El nodo raiz del plan, para recorridos que necesitan la jerarquia.
     *
     * @param  array{sql: string, bindings: list<mixed>}  $query
     * @return array<mixed, mixed>|null
     */
    public static function root(string $explain, array $query): ?array
    {
        $explained = DB::select($explain.$query['sql'], $query['bindings']);
        /** @var mixed $plan */
        $plan = isset($explained[0]) && \is_object($explained[0]) ? ($explained[0]->{'QUERY PLAN'} ?? null) : null;
        /** @var mixed $decoded */
        $decoded = json_decode(\is_string($plan) ? $plan : '', true);
        /** @var mixed $first */
        $first = \is_array($decoded) ? ($decoded[0] ?? null) : null;
        /** @var mixed $root */
        $root = \is_array($first) ? ($first['Plan'] ?? null) : null;

        return \is_array($root) ? $root : null;
    }

    /**
     * @param  array<mixed, mixed>  $root
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $root): array
    {
        /** @var list<array<string, mixed>> $nodes */
        $nodes = [];
        /** @var list<array<mixed, mixed>> $pending */
        $pending = [$root];

        while ($pending !== []) {
            $current = array_pop($pending);
            /** @var array<string, mixed> $current */
            $nodes[] = $current;

            /** @var mixed $children */
            $children = $current['Plans'] ?? [];

            foreach (\is_array($children) ? $children : [] as $child) {
                if (\is_array($child)) {
                    $pending[] = $child;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public static function scansSequentially(array $nodes, string $table): bool
    {
        foreach ($nodes as $node) {
            if (($node['Node Type'] ?? '') === 'Seq Scan' && ($node['Relation Name'] ?? '') === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public static function usesIndex(array $nodes, string $index): bool
    {
        foreach ($nodes as $node) {
            if (($node['Index Name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si algun nodo `Sort` (o `Incremental Sort`) ordena filas que salen de
     * `$table`: es decir, si `$table` aparece por debajo de una ordenacion.
     *
     * @param  array<mixed, mixed>  $root
     */
    public static function sortsOver(array $root, string $table): bool
    {
        foreach (self::flatten($root) as $node) {
            if (\in_array($node['Node Type'] ?? '', ['Sort', 'Incremental Sort'], true)) {
                foreach (self::flatten($node) as $below) {
                    if (($below['Relation Name'] ?? '') === $table) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
