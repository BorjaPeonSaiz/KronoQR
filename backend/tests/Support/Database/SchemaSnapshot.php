<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * El esquema `public` leido del catalogo, en lineas de texto comparables, para
 * comprobar que un `down()` deja la base como estaba (RF-PD-10; R5-BD-04 de la
 * re-verificacion de la 2.2.0).
 *
 * ## Que entra
 *
 * Una linea por objeto, con todo lo que lo define y nada que dependa de la
 * historia de la base (OID, `attnum`, valores de secuencia, estadisticas):
 *
 * - **relaciones** (tablas, vistas, secuencias, indices): tipo, propietario,
 *   persistencia, opciones, clave de particion, definicion de la vista y
 *   privilegios;
 * - **columnas**: tipo, `NOT NULL`, valor por omision, identidad, generada,
 *   intercalacion y privilegios de columna;
 * - **indices** (`pg_get_indexdef`) y si son validos;
 * - **restricciones** (`pg_get_constraintdef`) y si estan validadas;
 * - **funciones**: firma, propietario, definicion completa
 *   (`pg_get_functiondef`: cuerpo, `SECURITY DEFINER`, `search_path`,
 *   `lock_timeout`) y privilegios;
 * - **triggers** (`pg_get_triggerdef`) y si estan activos;
 * - **politicas**, **tipos** propios, **objetos de estadisticas**,
 *   **parametros de las secuencias**, **extensiones**, el **esquema** con sus
 *   privilegios y los **privilegios por omision**.
 *
 * Los privilegios se expanden con `aclexplode` sobre `acldefault` cuando la
 * columna es nula, asi que un `GRANT` y su `REVOKE` que dejan lo mismo que no
 * haber hecho nada comparan igual, y uno que deja un residuo no.
 *
 * ## Que no entra, y por que
 *
 * - **Las particiones** (`relispartition`) y sus indices: `audit_log` las crea
 *   por año segun los datos (ADR-027), y la semilla crea las de sus fechas. Se
 *   van con la tabla madre, que si entra.
 * - **Lo que pertenece a una extension** (`pg_depend` de tipo `e`):
 *   `btree_gist` y `unaccent` crean funciones y tipos en `public` que no son
 *   del producto.
 * - **El orden de las columnas.** El `down()` de una contraccion vuelve a
 *   añadir al final la columna que el `up()` quito, y PostgreSQL no sabe
 *   ponerla donde estaba (`installation_settings`, `2026_09_05_100000`). Nada
 *   del producto depende de la posicion: el codigo nombra las columnas y
 *   `pg_dump` tambien.
 * - Las filas: esto compara el esquema, no los datos.
 */
final class SchemaSnapshot
{
    /**
     * Las lineas del esquema, ordenadas.
     *
     * @return list<string>
     */
    public static function of(ConnectionInterface $connection): array
    {
        /** @var list<object{line: string}> $rows */
        $rows = $connection->select(self::SQL);

        $lines = array_map(static fn (object $row): string => $row->line, $rows);
        sort($lines, SORT_STRING);

        return $lines;
    }

    /**
     * Lo que sobra y lo que falta en `$actual` respecto de `$expected`.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     * @return list<string>
     */
    public static function differences(array $expected, array $actual): array
    {
        $missing = array_map(static fn (string $line): string => '- '.$line, array_values(array_diff($expected, $actual)));
        $extra = array_map(static fn (string $line): string => '+ '.$line, array_values(array_diff($actual, $expected)));

        return [...$missing, ...$extra];
    }

    /**
     * Una sola consulta: cada rama da lineas de un tipo de objeto.
     *
     * `acl()` no existe en SQL; la expansion se repite en cada rama con la misma
     * forma: `grantee:privilegio[*]` ordenado, `PUBLIC` para el OID 0.
     */
    private const string SQL = <<<'SQL'
        WITH ext AS (
            SELECT d.classid, d.objid FROM pg_depend d WHERE d.deptype = 'e'
        ), rel AS (
            SELECT c.*
              FROM pg_class c
             WHERE c.relnamespace = 'public'::regnamespace
               AND NOT c.relispartition
               AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_class'::regclass AND ext.objid = c.oid)
               -- Un indice de una particion no tiene `relispartition` si no
               -- cuelga de un indice particionado: se mira su tabla.
               AND NOT EXISTS (
                   SELECT 1 FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
                    WHERE i.indexrelid = c.oid AND t.relispartition
               )
        )
        SELECT 'schema|' || n.nspname || '|' || pg_get_userbyid(n.nspowner) || '|'
               || coalesce((SELECT string_agg(
                       CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END
                       || ':' || a.privilege_type || CASE WHEN a.is_grantable THEN '*' ELSE '' END, ',' ORDER BY 1)
                    FROM aclexplode(coalesce(n.nspacl, acldefault('n', n.nspowner))) a), '') AS line
          FROM pg_namespace n
         WHERE n.nspname NOT LIKE 'pg\_%' AND n.nspname <> 'information_schema'
        UNION ALL
        SELECT 'extension|' || e.extname || '|' || e.extversion || '|' || e.extnamespace::regnamespace::text
          FROM pg_extension e
        UNION ALL
        SELECT 'default_acl|' || pg_get_userbyid(d.defaclrole) || '|' || coalesce(d.defaclnamespace::regnamespace::text, '*')
               || '|' || d.defaclobjtype::text || '|'
               || coalesce((SELECT string_agg(
                       CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END
                       || ':' || a.privilege_type, ',' ORDER BY 1)
                    FROM aclexplode(d.defaclacl) a), '')
          FROM pg_default_acl d
        UNION ALL
        SELECT 'relation|' || c.relname || '|' || c.relkind::text || '|' || pg_get_userbyid(c.relowner) || '|' || c.relpersistence::text
               || '|rls=' || c.relrowsecurity || '/' || c.relforcerowsecurity
               || '|options=' || coalesce(array_to_string(c.reloptions, ','), '')
               || '|partition=' || coalesce(pg_get_partkeydef(c.oid), '')
               || '|view=' || coalesce(CASE WHEN c.relkind IN ('v', 'm') THEN pg_get_viewdef(c.oid) END, '')
               || '|comment=' || coalesce(obj_description(c.oid, 'pg_class'), '')
               || '|acl=' || coalesce((SELECT string_agg(
                       CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END
                       || ':' || a.privilege_type || CASE WHEN a.is_grantable THEN '*' ELSE '' END, ',' ORDER BY 1)
                    FROM aclexplode(coalesce(c.relacl,
                         acldefault((CASE WHEN c.relkind = 'S' THEN 's' ELSE 'r' END)::"char", c.relowner))) a), '')
          FROM rel c
         WHERE c.relkind <> 'i' AND c.relkind <> 'I'
        UNION ALL
        SELECT 'column|' || c.relname || '|' || a.attname
               || '|' || format_type(a.atttypid, a.atttypmod)
               || '|notnull=' || a.attnotnull
               || '|default=' || coalesce(pg_get_expr(ad.adbin, ad.adrelid), '')
               || '|identity=' || a.attidentity::text || '|generated=' || a.attgenerated::text
               || '|collation=' || coalesce((SELECT co.collname FROM pg_collation co WHERE co.oid = a.attcollation AND a.attcollation <> 0), '')
               || '|comment=' || coalesce(col_description(c.oid, a.attnum), '')
               || '|acl=' || coalesce((SELECT string_agg(
                       CASE x.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(x.grantee) END
                       || ':' || x.privilege_type, ',' ORDER BY 1)
                    FROM aclexplode(a.attacl) x), '')
          FROM rel c
          JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
          LEFT JOIN pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
         WHERE c.relkind IN ('r', 'p', 'v', 'm', 'f')
        UNION ALL
        SELECT 'index|' || t.relname || '|' || c.relname || '|' || pg_get_indexdef(c.oid) || '|valid=' || i.indisvalid
          FROM rel c
          JOIN pg_index i ON i.indexrelid = c.oid
          JOIN pg_class t ON t.oid = i.indrelid
        UNION ALL
        SELECT 'constraint|' || coalesce(t.relname, '') || '|' || co.conname || '|' || co.contype::text
               || '|' || pg_get_constraintdef(co.oid) || '|validated=' || co.convalidated
          FROM pg_constraint co
          LEFT JOIN pg_class t ON t.oid = co.conrelid
         WHERE co.connamespace = 'public'::regnamespace
           AND (t.oid IS NULL OR NOT t.relispartition)
           AND co.conparentid = 0
        UNION ALL
        SELECT 'function|' || p.oid::regprocedure::text || '|' || pg_get_userbyid(p.proowner)
               || '|acl=' || coalesce((SELECT string_agg(
                       CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END
                       || ':' || a.privilege_type || CASE WHEN a.is_grantable THEN '*' ELSE '' END, ',' ORDER BY 1)
                    FROM aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a), '')
               || '|comment=' || coalesce(obj_description(p.oid, 'pg_proc'), '')
               || '|' || CASE WHEN p.prokind IN ('a', 'w') THEN p.prokind::text ELSE pg_get_functiondef(p.oid) END
          FROM pg_proc p
         WHERE p.pronamespace = 'public'::regnamespace
           AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_proc'::regclass AND ext.objid = p.oid)
        UNION ALL
        SELECT 'trigger|' || c.relname || '|' || tg.tgname || '|' || pg_get_triggerdef(tg.oid) || '|enabled=' || tg.tgenabled::text
          FROM pg_trigger tg
          JOIN rel c ON c.oid = tg.tgrelid
         WHERE NOT tg.tgisinternal
        UNION ALL
        SELECT 'policy|' || c.relname || '|' || po.polname || '|' || po.polcmd::text || '|' || po.polpermissive
               || '|' || coalesce(pg_get_expr(po.polqual, po.polrelid), '') || '|' || coalesce(pg_get_expr(po.polwithcheck, po.polrelid), '')
          FROM pg_policy po
          JOIN rel c ON c.oid = po.polrelid
        UNION ALL
        SELECT 'type|' || t.typname || '|' || t.typtype::text || '|' || pg_get_userbyid(t.typowner) || '|'
               || coalesce((SELECT string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder) FROM pg_enum e WHERE e.enumtypid = t.oid), '')
               || '|' || coalesce(format_type(t.typbasetype, t.typtypmod), '')
          FROM pg_type t
         WHERE t.typnamespace = 'public'::regnamespace
           AND t.typtype IN ('e', 'd', 'r', 'm')
           AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_type'::regclass AND ext.objid = t.oid)
        UNION ALL
        SELECT 'statistics|' || s.stxname || '|' || pg_get_userbyid(s.stxowner) || '|' || pg_get_statisticsobjdef(s.oid)
          FROM pg_statistic_ext s
         WHERE s.stxnamespace = 'public'::regnamespace
        UNION ALL
        SELECT 'sequence|' || c.relname || '|' || format_type(sq.seqtypid, NULL) || '|' || sq.seqstart || '|' || sq.seqincrement
               || '|' || sq.seqmin || '|' || sq.seqmax || '|' || sq.seqcache || '|' || sq.seqcycle
               || '|owned_by=' || coalesce((
                    SELECT t.relname || '.' || a.attname
                      FROM pg_depend d
                      JOIN pg_class t ON t.oid = d.refobjid
                      JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
                     WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i')
                     LIMIT 1), '')
          FROM rel c
          JOIN pg_sequence sq ON sq.seqrelid = c.oid
        SQL;
}
