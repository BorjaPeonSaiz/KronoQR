<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Database\QueryPlans;
use Tests\Support\Database\RefreshDatabaseAsOwner;

/*
 * Ninguna clave ajena que impide borrar al padre se queda sin indice
 * (RNF-P-02, regla dura 5; V21-5-BE-4 de la 2.2.1, migracion
 * `2026_10_28_100200`).
 *
 * Con `ON DELETE RESTRICT` (o `NO ACTION`, que comprueba lo mismo al final de
 * la sentencia), borrar una fila del padre ejecuta por cada clave ajena
 * `SELECT 1 FROM ONLY hija x WHERE columna = $1 FOR KEY SHARE OF x`. Sin un
 * indice que empiece por esa columna, es un `Seq Scan` de la hija por cada fila
 * borrada. La prueba lee el catalogo: una clave ajena nueva sin su indice la
 * hace fallar el mismo dia en que se escribe.
 *
 * Y, con el recorrido secuencial penalizado, comprueba que la consulta de
 * PostgreSQL entra de verdad por el indice: los de las columnas que admiten
 * `NULL` son parciales (`WHERE columna IS NOT NULL`), y la comprobacion solo
 * los puede usar porque `columna = $1` implica que no es nula. Con las tablas
 * vacias de la prueba el plan natural seria leerlas enteras, que es lo barato.
 *
 * ## Alcance
 *
 * **Solo las claves ajenas `RESTRICT` y `NO ACTION`** (`confdeltype` `r` y
 * `a`): son las que hacen que borrar al padre falle o se arrastre, y las que
 * recorre la purga del registro. Las `SET NULL` y `CASCADE` tambien lanzan una
 * consulta por fila borrada del padre, pero sus padres (usuarios, departamentos,
 * dispositivos) casi nunca se borran: quedan fuera a proposito.
 *
 * **Y solo de una columna.** Hoy no hay ninguna compuesta; si aparece, la
 * primera prueba falla, porque comprobar que su indice empieza por todas sus
 * columnas, en el orden de la clave, es otra consulta que nadie ha escrito.
 *
 * Con el rol dueño ({@see RefreshDatabaseAsOwner}): `FOR KEY SHARE` pide un
 * privilegio que la aplicacion no tiene en varias de estas tablas, y
 * PostgreSQL ejecuta la comprobacion real como dueño de la tabla hija.
 */

uses(RefreshDatabaseAsOwner::class);

/**
 * Las claves ajenas de una columna `RESTRICT` o `NO ACTION` del esquema
 * `public`, con su tabla y su columna. Las compuestas no salen aqui: las
 * cuenta {@see restrictCompositeForeignKeys()}.
 *
 * @return list<object{tabla: string, columna: string, restriccion: string}>
 */
function restrictForeignKeys(): array
{
    /** @var list<object{tabla: string, columna: string, restriccion: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS tabla,
               a.attname                  AS columna,
               c.conname                  AS restriccion
          FROM pg_constraint c
          JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
         WHERE c.contype = 'f'
           AND c.confdeltype IN ('r', 'a')
           AND c.connamespace = 'public'::regnamespace
           AND pg_catalog.cardinality(c.conkey) = 1
         ORDER BY 1, 2
        SQL);

    return $rows;
}

/**
 * Las claves ajenas compuestas `RESTRICT` o `NO ACTION` del esquema `public`.
 *
 * @return list<string>
 */
function restrictCompositeForeignKeys(): array
{
    /** @var list<object{nombre: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text || '.' || c.conname AS nombre
          FROM pg_constraint c
         WHERE c.contype = 'f'
           AND c.confdeltype IN ('r', 'a')
           AND c.connamespace = 'public'::regnamespace
           AND pg_catalog.cardinality(c.conkey) > 1
         ORDER BY 1
        SQL);

    return array_map(static fn (object $row): string => $row->nombre, $rows);
}

it('toda clave ajena RESTRICT tiene un indice valido que empieza por su columna', function (): void {
    // Una compuesta no se puede vigilar con la consulta de abajo, que solo
    // mira la primera columna del indice: antes de añadirla, hay que
    // ampliar esta prueba.
    expect(restrictCompositeForeignKeys())->toBe([], 'Claves ajenas RESTRICT compuestas que esta prueba no sabe vigilar');

    $keys = restrictForeignKeys();

    $missing = [];

    foreach ($keys as $key) {
        /** @var object{indexed: bool} $row */
        $row = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                  FROM pg_index i
                  JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                 WHERE i.indrelid = ?::regclass
                   AND a.attname = ?
                   AND i.indisvalid
                   -- Un parcial solo sirve si su predicado es que la columna
                   -- no es nula: `columna = $1` lo implica. Con otro (`status =
                   -- 'active'`) la comprobacion no lo puede usar.
                   AND (i.indpred IS NULL
                        OR pg_get_expr(i.indpred, i.indrelid) = '(' || quote_ident(a.attname) || ' IS NOT NULL)')
            ) AS indexed
            SQL, [$key->tabla, $key->columna]);

        if (! $row->indexed) {
            $missing[] = $key->tabla.'.'.$key->columna.' ('.$key->restriccion.')';
        }
    }

    // Que la consulta del catalogo encuentra algo: con una lista vacia la
    // prueba pasaria sin mirar nada.
    expect(count($keys))->toBeGreaterThan(30)
        ->and($missing)->toBe([], 'Claves ajenas RESTRICT sin indice: '.implode(', ', $missing));
})->group('RNF-P-02');

it('la comprobacion de cada clave ajena RESTRICT entra por su indice, tambien por los parciales', function (): void {
    DB::statement('SET LOCAL enable_seqscan = off');

    $sequential = [];

    foreach (restrictForeignKeys() as $key) {
        // La misma forma que la consulta de `ri_triggers.c`. El SQL lo escribe
        // PostgreSQL, no un adaptador: por eso aqui se transcribe.
        $nodes = QueryPlans::nodes([
            'sql' => sprintf(
                'SELECT 1 FROM ONLY %s x WHERE %s OPERATOR(pg_catalog.=) ? FOR KEY SHARE OF x',
                $key->tabla,
                $key->columna,
            ),
            'bindings' => [1],
        ]);

        if (QueryPlans::scansSequentially($nodes, $key->tabla)) {
            $sequential[] = $key->tabla.'.'.$key->columna;
        }
    }

    expect($sequential)->toBe([], 'Comprobaciones que siguen leyendo la tabla entera: '.implode(', ', $sequential));
})->group('RNF-P-02');
