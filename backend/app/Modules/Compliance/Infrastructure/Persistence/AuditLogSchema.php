<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use LogicException;

/**
 * El SQL de `audit_log`: nombres, particiones y permisos, en un solo sitio
 * (ADR-027, regla dura 6).
 *
 * **Por que no vive dentro de la migracion.** Las particiones las crean dos
 * caminos: las migraciones, con el rol de migracion, y la funcion
 * `audit_log_create_partition` (ADR-042), que la tarea programada invoca con el
 * rol de la aplicacion para el año siguiente o, si faltara, para el año en
 * curso. Si cada camino escribiera su propio `CREATE TABLE ... PARTITION OF` y
 * sus propios permisos, tarde o temprano uno se olvidaria del `REVOKE`: **los
 * permisos NO se heredan al adjuntar una particion**, y los `ALTER DEFAULT
 * PRIVILEGES` del migrador se aplican tambien dentro de la funcion, asi que una
 * particion creada sin revocar dejaria a la aplicacion con `UPDATE` y `DELETE`
 * sobre el registro probatorio de ese año, y nada fallaria. Crear y restringir
 * tienen que ser la misma operacion, y la lista de permisos esta escrita una
 * sola vez ({@see self::appendOnlyGrantTemplates()}): de ella salen tanto las
 * sentencias de las migraciones como el cuerpo de la funcion.
 */
final class AuditLogSchema
{
    public const string TABLE = 'audit_log';

    public const string ANCHORS_TABLE = 'audit_chain_anchors';

    /**
     * La funcion que suelta una particion ya sellada (tarea 2.10). El nombre
     * vive aqui porque lo nombran tres sitios: la migracion que la crea, el
     * adaptador que la invoca y la prueba que comprueba quien puede ejecutarla.
     */
    public const string DROP_FUNCTION = 'audit_log_drop_sealed_partition';

    /**
     * La funcion que crea la particion anual (ADR-042). Vive aqui por el mismo
     * motivo que {@see self::DROP_FUNCTION}: la nombran la migracion que la
     * crea, el adaptador que la invoca y las pruebas que comprueban quien puede
     * ejecutarla.
     */
    public const string CREATE_FUNCTION = 'audit_log_create_partition';

    /**
     * El `search_path` de toda funcion `SECURITY DEFINER` de esta clase. `pg_temp`
     * va explicito y al final: si no aparece, PostgreSQL lo busca PRIMERO para
     * las relaciones, y `PUBLIC` tiene `TEMPORARY` sobre la base, asi que una
     * tabla temporal de quien llama podria suplantar a una del esquema.
     */
    public const string DEFINER_SEARCH_PATH = 'pg_catalog, pg_temp';

    /**
     * El `search_path` con el que la migracion de la tarea 2.10 creo la funcion
     * de purga. Solo lo usa el `down()` de la migracion que la alinea con
     * {@see self::DEFINER_SEARCH_PATH}.
     */
    public const string LEGACY_DROP_FUNCTION_SEARCH_PATH = 'pg_catalog, public';

    /**
     * Primer año con particion. Es el año del primer despliegue del producto y
     * el literal de ADR-027 y del doc 01 §5.5.
     */
    public const int FIRST_YEAR = 2026;

    /** La marca que ocupa el lugar de la relacion en {@see self::appendOnlyGrantTemplates()}. */
    private const string RELATION_MARK = '{relation}';

    public static function partitionName(int $year): string
    {
        return self::TABLE.'_'.$year;
    }

    /**
     * La funcion que suelta una particion ya sellada (tarea 2.10, ADR-027).
     *
     * Es `SECURITY DEFINER` y pertenece al **propietario** de `audit_log`, que
     * es el rol de migracion. `ALTER TABLE … DETACH PARTITION` exige ser
     * propietario, y hacer propietario al rol de mantenimiento le daria de paso
     * poder retirar los `REVOKE` que sostienen la regla dura 6 -un propietario
     * puede volver a otorgarse lo que se le revoque-. Con la funcion, el rol de
     * mantenimiento puede hacer **exactamente una cosa** y ninguna otra.
     *
     * Y la funcion **exige el ancla desde dentro**: sin sello en
     * `audit_chain_anchors` no suelta nada, aunque quien la llame se equivoque de
     * orden. Es la ultima red antes de que un hueco quede sin explicar (RS-07).
     *
     * @return list<string>
     */
    public static function dropFunctionStatements(): array
    {
        $function = self::quoteIdentifier(self::DROP_FUNCTION);
        $maintenance = self::quoteIdentifier(self::maintenanceRole());

        // Se sustituyen marcas y no se usa `sprintf`: el cuerpo esta lleno de
        // `%I` y `%` de `format()` y de `RAISE`, y duplicarlos todos para
        // esquivar a `sprintf` convierte una funcion legible en un jeroglifico
        // que nadie revisa. Las marcas salen de constantes de esta clase.
        //
        // El `search_path` de abajo es el historico de la tarea 2.10 y NO se
        // cambia aqui: la migracion 2026_09_29_100000 lo alinea con
        // `DEFINER_SEARCH_PATH` mediante `ALTER FUNCTION`, para las instalaciones
        // nuevas y las existentes por igual, y su `down()` lo devuelve
        // exactamente a este valor. Cambiarlo aqui haria que ese `down()` no
        // restaurase lo que habia en una instalacion limpia.
        $body = strtr(<<<'SQL'
            CREATE OR REPLACE FUNCTION :function:(p_year integer)
            RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $kronoqr$
            DECLARE
                partition_name text := ':table:_' || p_year::text;
            BEGIN
                IF p_year < :first_year: OR p_year > 9999 THEN
                    RAISE EXCEPTION 'Ano de particion fuera de rango: %', p_year;
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_inherits
                    JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
                    JOIN pg_class child  ON child.oid  = pg_inherits.inhrelid
                    JOIN pg_namespace ns ON ns.oid     = parent.relnamespace
                    WHERE parent.relname = ':table:' AND ns.nspname = 'public'
                      AND child.relname = partition_name
                ) THEN
                    RAISE EXCEPTION 'La particion % no esta adjunta a :table:', partition_name;
                END IF;

                IF NOT EXISTS (SELECT 1 FROM public.:anchors: WHERE partition_year = p_year) THEN
                    RAISE EXCEPTION 'La particion % no tiene ancla sellada: no se suelta (ADR-027)', partition_name;
                END IF;

                EXECUTE format('ALTER TABLE public.:table: DETACH PARTITION public.%I', partition_name);
                EXECUTE format('DROP TABLE public.%I', partition_name);
            END;
            $kronoqr$
            SQL, [
            ':function:' => $function,
            ':table:' => self::TABLE,
            ':anchors:' => self::ANCHORS_TABLE,
            ':first_year:' => (string) self::FIRST_YEAR,
        ]);

        return [
            $body,
            // Nadie por defecto, ni siquiera el rol de la aplicacion: `PUBLIC`
            // recibe `EXECUTE` sobre toda funcion nueva si no se le retira.
            sprintf('REVOKE ALL ON FUNCTION %s(integer) FROM PUBLIC', $function),
            sprintf('GRANT EXECUTE ON FUNCTION %s(integer) TO %s', $function, $maintenance),
        ];
    }

    public static function dropFunctionRemovalStatement(): string
    {
        return sprintf('DROP FUNCTION IF EXISTS %s(integer)', self::quoteIdentifier(self::DROP_FUNCTION));
    }

    /**
     * `ALTER FUNCTION … SET search_path` sobre la funcion de purga. Conserva
     * propietario y ACL: solo cambia la configuracion de la funcion.
     */
    public static function dropFunctionSearchPathStatement(string $searchPath): string
    {
        if (! \in_array($searchPath, [self::DEFINER_SEARCH_PATH, self::LEGACY_DROP_FUNCTION_SEARCH_PATH], true)) {
            throw new InvalidArgumentException('search_path no previsto para la funcion de purga: '.$searchPath);
        }

        return sprintf(
            'ALTER FUNCTION public.%s(integer) SET search_path = %s',
            self::quoteIdentifier(self::DROP_FUNCTION),
            $searchPath,
        );
    }

    /**
     * La funcion que crea la particion anual de `audit_log` (ADR-042).
     *
     * Es `SECURITY DEFINER` y pertenece al rol de migracion, igual que la de
     * purga: `CREATE TABLE … PARTITION OF` exige ser propietario de la tabla
     * madre, y hacer propietario —o dar `CREATE` en el esquema— al rol de la
     * aplicacion le permitiria volver a otorgarse `UPDATE` y `DELETE`. Con la
     * funcion, el rol de la aplicacion puede pedir exactamente una cosa: la
     * particion del año UTC en curso o del siguiente.
     *
     * Lo que la hace segura con un propietario superusuario:
     *
     * - recibe un `integer` y construye el nombre con `format('%I')` y los
     *   limites con `%L`: no entra texto de quien llama;
     * - califica todo con `public.` y fija `search_path = pg_catalog, pg_temp`;
     * - rechaza años fuera de `[año UTC, año UTC + 1]`, por debajo de
     *   `FIRST_YEAR` y **sellados** en `audit_chain_anchors` (un año purgado no
     *   puede reaparecer vacio para recibir entradas retrodatadas);
     * - falla, en vez de no hacer nada, si existe una tabla homonima que no es
     *   particion;
     * - serializa con un cerrojo consultivo y acota la espera con `lock_timeout`,
     *   porque `PARTITION OF` bloquea en exclusiva la tabla madre y los fichajes
     *   harian cola detras;
     * - deja la particion con los permisos de {@see self::appendOnlyGrantTemplates()}
     *   en la misma operacion.
     *
     * Devuelve `true` si la ha creado y `false` si ya existia.
     *
     * @return list<string>
     */
    public static function createFunctionStatements(): array
    {
        $function = self::quoteIdentifier(self::CREATE_FUNCTION);
        $application = self::quoteIdentifier(self::applicationRole());

        $body = strtr(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.:function:(p_year integer)
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = :search_path:
            SET lock_timeout = '5s'
            AS $kronoqr$
            DECLARE
                utc_year       integer := extract(year FROM (now() AT TIME ZONE 'UTC'))::integer;
                partition_name text    := ':table:_' || p_year::text;
                existing       oid;
            BEGIN
                IF p_year IS NULL OR p_year < :first_year: OR p_year < utc_year OR p_year > utc_year + 1 THEN
                    RAISE EXCEPTION 'Ano de particion fuera de rango: %', p_year USING ERRCODE = '22023';
                END IF;

                IF EXISTS (SELECT 1 FROM public.:anchors: WHERE partition_year = p_year) THEN
                    RAISE EXCEPTION 'El ano % esta sellado: no se recrea su particion (ADR-027)', p_year
                        USING ERRCODE = '22023';
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('kronoqr.:function_name:'));

                SELECT c.oid INTO existing
                  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public' AND c.relname = partition_name;

                IF existing IS NOT NULL THEN
                    IF EXISTS (SELECT 1 FROM pg_inherits
                                WHERE inhrelid = existing AND inhparent = 'public.:table:'::regclass) THEN
                        RETURN false;
                    END IF;
                    RAISE EXCEPTION 'public.% existe y no es particion de :table:', partition_name
                        USING ERRCODE = '42P07';
                END IF;

                EXECUTE format(
                    'CREATE TABLE public.%I PARTITION OF public.:table: FOR VALUES FROM (%L) TO (%L)',
                    partition_name, p_year || '-01-01T00:00:00Z', (p_year + 1) || '-01-01T00:00:00Z'
                );

            :grants:
                RETURN true;
            END;
            $kronoqr$
            SQL, [
            ':function_name:' => self::CREATE_FUNCTION,
            ':function:' => $function,
            ':search_path:' => self::DEFINER_SEARCH_PATH,
            ':table:' => self::TABLE,
            ':anchors:' => self::ANCHORS_TABLE,
            ':first_year:' => (string) self::FIRST_YEAR,
            ':grants:' => self::functionGrantBlock(),
        ]);

        return [
            $body,
            // `PUBLIC` recibe `EXECUTE` sobre toda funcion nueva si no se le
            // retira. El rol de mantenimiento tampoco la necesita.
            sprintf('REVOKE ALL ON FUNCTION public.%s(integer) FROM PUBLIC', $function),
            sprintf('GRANT EXECUTE ON FUNCTION public.%s(integer) TO %s', $function, $application),
        ];
    }

    public static function createFunctionRemovalStatement(): string
    {
        return sprintf('DROP FUNCTION IF EXISTS public.%s(integer)', self::quoteIdentifier(self::CREATE_FUNCTION));
    }

    /**
     * `CREATE TABLE … PARTITION OF` mas los permisos de esa particion, en el
     * orden en que hay que ejecutarlos.
     *
     * El rango es `[1 de enero del año, 1 de enero del siguiente)` en UTC, con
     * `Z` explicita. Sin la `Z`, PostgreSQL interpretaria el literal en la zona
     * de la sesion y el limite de la particion se moveria: en `Europe/Madrid`,
     * las entradas del 31 de diciembre a las 23:30 UTC caerian en el año
     * siguiente (regla dura 3).
     *
     * @return list<string>
     */
    public static function createPartitionStatements(int $year): array
    {
        $partition = self::partitionName($year);

        return [
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
                self::quoteIdentifier($partition),
                self::quoteIdentifier(self::TABLE),
                self::quoteLiteral($year.'-01-01T00:00:00Z'),
                self::quoteLiteral(($year + 1).'-01-01T00:00:00Z'),
            ),
            ...self::appendOnlyGrantStatements($partition),
        ];
    }

    /**
     * Los permisos que hacen de una relacion algo solo-append para la
     * aplicacion (regla dura 6, doc 01 §5.5 «Permisos»).
     *
     * Tres sentencias y las tres hacen falta:
     *
     * 1. `REVOKE ALL … FROM PUBLIC` — sin esto, cualquier rol futuro heredaria
     *    de `PUBLIC` lo que `PUBLIC` tenga.
     * 2. `REVOKE ALL … FROM` el rol de aplicacion — deshace lo que le hayan
     *    dado los `ALTER DEFAULT PRIVILEGES`, que otorgan las cuatro
     *    operaciones a toda tabla nueva.
     * 3. `GRANT INSERT, SELECT` — y nada mas. Ni `UPDATE`, ni `DELETE`, ni
     *    `TRUNCATE`.
     *
     * El rol de mantenimiento recibe `SELECT`: para sellar un ancla antes de
     * soltar la particion (tarea 2.10) tiene que poder leerla y verificar su
     * cadena. `DELETE` tampoco lo recibe: la purga es `DROP PARTITION`.
     *
     * @return list<string>
     */
    public static function appendOnlyGrantStatements(string $relation): array
    {
        $table = self::quoteIdentifier($relation);

        return array_map(
            static fn (string $template): string => str_replace(self::RELATION_MARK, $table, $template),
            self::appendOnlyGrantTemplates(),
        );
    }

    /**
     * La lista de permisos solo-append, **escrita una vez**, con la relacion
     * como marca. De aqui salen las sentencias de las migraciones
     * ({@see self::appendOnlyGrantStatements()}) y el cuerpo de la funcion que
     * crea particiones ({@see self::functionGrantBlock()}): si alguien añade o
     * quita un permiso, cambia en los dos caminos a la vez.
     *
     * @return list<string>
     */
    private static function appendOnlyGrantTemplates(): array
    {
        $application = self::quoteIdentifier(self::applicationRole());
        $maintenance = self::quoteIdentifier(self::maintenanceRole());
        $relation = self::RELATION_MARK;

        return [
            'REVOKE ALL ON TABLE '.$relation.' FROM PUBLIC',
            'REVOKE ALL ON TABLE '.$relation.' FROM '.$application,
            'REVOKE ALL ON TABLE '.$relation.' FROM '.$maintenance,
            'GRANT INSERT, SELECT ON TABLE '.$relation.' TO '.$application,
            'GRANT SELECT ON TABLE '.$relation.' TO '.$maintenance,
        ];
    }

    /**
     * Los permisos de {@see self::appendOnlyGrantTemplates()} como sentencias
     * PL/pgSQL que actuan sobre la variable `partition_name` de la funcion.
     *
     * Cada plantilla va como literal de `format()`, entre comillas simples. Es
     * seguro porque las plantillas no pueden contener `'` ni `%`: sus unicas
     * piezas variables son nombres de rol que {@see self::assertIdentifier()}
     * limita a `[A-Za-z0-9_]`. Se comprueba igual, porque si algun dia una
     * plantilla los contuviera, el cuerpo de la funcion cambiaria de sentido
     * en silencio.
     */
    private static function functionGrantBlock(): string
    {
        $lines = [];

        foreach (self::appendOnlyGrantTemplates() as $template) {
            if (str_contains($template, "'") || str_contains($template, '%')) {
                throw new LogicException(
                    'La plantilla de permisos «'.$template.'» contiene comillas simples o «%»: '
                    .'no puede ir como literal de format() en la funcion '.self::CREATE_FUNCTION.'.'
                );
            }

            $lines[] = "    EXECUTE format('".str_replace(self::RELATION_MARK, 'public.%I', $template)."', partition_name);";
        }

        return implode("\n", $lines);
    }

    public static function applicationRole(): string
    {
        return self::assertIdentifier(Config::string('database.roles.application', 'fichaje_app'));
    }

    public static function maintenanceRole(): string
    {
        return self::assertIdentifier(Config::string('database.roles.maintenance', 'fichaje_maintenance'));
    }

    public static function migrationRole(): string
    {
        return self::assertIdentifier(Config::string('database.roles.migration', 'fichaje_migrator'));
    }

    /**
     * Un nombre de rol no puede ir como parametro enlazado en un `GRANT`, asi
     * que se acota lo que puede ser: letras, digitos y `_`, empezando por letra
     * o `_`. Es mas estricto que PostgreSQL a proposito. Los roles salen de
     * `config/database.php`, no de una peticion, pero la unica forma de que un
     * `GRANT` construido por concatenacion sea seguro es que su entrada no
     * pueda contener nada mas.
     */
    public static function assertIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(
                'El nombre de rol «'.$identifier.'» no es un identificador simple de PostgreSQL. '
                // Sin nombrar las variables del rol de migracion: ADR-042 prohibe
                // que el codigo de la aplicacion las mencione, y la prueba de
                // arquitectura lo comprueba. La lista completa esta en .env.example.
                .'Revisa los nombres de rol de base de datos del .env (los *_USERNAME de la seccion de base de datos de .env.example).'
            );
        }

        return $identifier;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"'.self::assertIdentifier($identifier).'"';
    }

    /**
     * Solo se usa con literales de fecha construidos aqui mismo a partir de un
     * `int`. Se escapa igual: un `str_replace` de mas cuesta nada y una
     * concatenacion sin escapar en una migracion es la que nadie revisa.
     */
    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
