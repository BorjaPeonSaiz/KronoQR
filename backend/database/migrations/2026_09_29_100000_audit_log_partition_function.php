<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La particion anual de `audit_log` la pide la aplicacion y la crea el motor
 * (ADR-042, Bloque 3 de la 2.2.0, hallazgo AUD-1, regla dura 6).
 *
 * ## El problema
 *
 * Hasta la 2.1.0, la tarea programada `compliance:ensure-audit-partitions`
 * resolvia la conexion del rol de migracion para crear la particion del año
 * siguiente: `CREATE TABLE … PARTITION OF` exige ser propietario de la tabla
 * madre. Eso obligaba a que el runtime tuviera la credencial de un rol que es
 * SUPERUSER, y con ella se podia reescribir `audit_log` y recalcular la cadena.
 *
 * ## La solucion
 *
 * Una funcion `SECURITY DEFINER` propiedad del rol de migracion,
 * `public.audit_log_create_partition(integer)`, que solo el rol de la aplicacion
 * puede ejecutar y que hace exactamente una cosa: crear la particion del año UTC
 * en curso o del siguiente, nunca de un año sellado, con los mismos permisos que
 * las demas ({@see AuditLogSchema::createFunctionStatements()}). Es el mismo
 * mecanismo que ya delega la purga al rol de mantenimiento (2026_09_03_100000).
 *
 * ## Que mas hace, y por que aqui
 *
 * - **Asegura las particiones del año en curso y del siguiente**, como
 *   migrador. Cada actualizacion deja asi dos años de margen aunque el
 *   planificador no haya corrido. Es idempotente.
 * - **Alinea el `search_path` de la funcion de purga** con el de esta
 *   (`pg_catalog, pg_temp`). Su cuerpo ya lo califica todo con `public.`, asi
 *   que no cambia su comportamiento; cierra la posibilidad de que una tabla
 *   temporal suplante una del esquema. `ALTER FUNCTION … SET` conserva
 *   propietario y ACL.
 *
 * ## Despliegue
 *
 * Solo añade (paso *expand*): una funcion, un `ALTER FUNCTION … SET` y, si
 * faltaran, particiones. Ninguna sentencia reescribe una tabla; la unica que
 * pide un bloqueo sobre `audit_log` es la creacion de una particion que falte, y
 * `lock_timeout` la hace rendirse en vez de dejar fichajes en cola.
 *
 * ## Reversible
 *
 * `down()` suelta la funcion y devuelve a la de purga su `search_path` anterior.
 * **No suelta ninguna particion**: son datos, y revertir esta migracion quita
 * una capacidad, nunca registro.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        foreach (AuditLogSchema::createFunctionStatements() as $statement) {
            DB::statement($statement);
        }

        // `gmdate` y no el puerto `Clock`: esto es una migracion, no dominio, y
        // el año que importa es el del reloj del servidor en UTC (regla dura 3).
        $currentYear = (int) gmdate('Y');

        foreach ([$currentYear, $currentYear + 1] as $year) {
            foreach (AuditLogSchema::createPartitionStatements($year) as $statement) {
                DB::statement($statement);
            }
        }

        DB::statement(AuditLogSchema::dropFunctionSearchPathStatement(AuditLogSchema::DEFINER_SEARCH_PATH));
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement(AuditLogSchema::createFunctionRemovalStatement());
        DB::statement(AuditLogSchema::dropFunctionSearchPathStatement(AuditLogSchema::LEGACY_DROP_FUNCTION_SEARCH_PATH));
    }
};
