<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * El SQL de `audit_log`: nombres, particiones y permisos, en un solo sitio
 * (ADR-027, regla dura 6).
 *
 * **Por que no vive dentro de la migracion.** La particion del año en curso la
 * crea la migracion y la del año siguiente la crea una tarea programada. Si cada
 * una escribiera su propio `CREATE TABLE ... PARTITION OF`, tarde o temprano una
 * de las dos se olvidaria del `REVOKE`: **los permisos NO se heredan al adjuntar
 * una particion**, asi que una particion creada sin revocar dejaria a la
 * aplicacion con `UPDATE` y `DELETE` sobre el registro probatorio de ese año, y
 * nada fallaria. Crear y restringir tienen que ser la misma operacion, escrita
 * una vez.
 */
final class AuditLogSchema
{
    public const string TABLE = 'audit_log';

    public const string ANCHORS_TABLE = 'audit_chain_anchors';

    /**
     * Primer año con particion. Es el año del primer despliegue del producto y
     * el literal de ADR-027 y del doc 01 §5.5.
     */
    public const int FIRST_YEAR = 2026;

    public static function partitionName(int $year): string
    {
        return self::TABLE.'_'.$year;
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
        $application = self::quoteIdentifier(self::applicationRole());
        $maintenance = self::quoteIdentifier(self::maintenanceRole());

        return [
            sprintf('REVOKE ALL ON TABLE %s FROM PUBLIC', $table),
            sprintf('REVOKE ALL ON TABLE %s FROM %s', $table, $application),
            sprintf('REVOKE ALL ON TABLE %s FROM %s', $table, $maintenance),
            sprintf('GRANT INSERT, SELECT ON TABLE %s TO %s', $table, $application),
            sprintf('GRANT SELECT ON TABLE %s TO %s', $table, $maintenance),
        ];
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
                .'Revisa DB_USERNAME, DB_MIGRATION_USERNAME y DB_MAINTENANCE_USERNAME en el .env.'
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
