<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el rol de mantenimiento necesita para purgar, y **nada mas**
 * (tarea 2.10, ADR-027, ADR-033, regla dura 6).
 *
 * ## El problema
 *
 * `ALTER TABLE … DETACH PARTITION` y `DROP TABLE` exigen ser **propietario** de
 * la tabla. El propietario de `audit_log` es el rol de migracion, y tiene que
 * seguir siendolo: un propietario puede volver a otorgarse lo que se le revoque,
 * asi que hacer propietario al rol de mantenimiento —el que ejecuta la purga—
 * le daria de paso poder retirarse los `REVOKE` de `UPDATE` y `DELETE` sobre el
 * registro probatorio. La garantia de la regla dura 6 quedaria en manos de quien
 * borra.
 *
 * ## La solucion
 *
 * Una funcion `SECURITY DEFINER` propiedad del rol de migracion, que hace
 * exactamente una cosa —soltar una particion anual de `audit_log` **que ya tiene
 * su ancla sellada**— y que solo el rol de mantenimiento puede ejecutar. Es el
 * mecanismo estandar de PostgreSQL para delegar un privilegio estrecho sin
 * repartir el ancho.
 *
 * Reparto resultante, que es el que comprueba la prueba de integracion:
 *
 * | Rol | `audit_log` | Soltar particion |
 * |---|---|---|
 * | `fichaje_app` | `INSERT`, `SELECT` | **no** |
 * | `fichaje_maintenance` | `SELECT` | si, por la funcion y solo si hay ancla |
 * | `fichaje_migrator` | propietario | si, es su tabla |
 *
 * ## Ademas: `DELETE` sobre el registro de jornada
 *
 * No hace falta ninguno nuevo. La purga del registro de jornada la ejecuta el rol
 * de la **aplicacion**, que ya tiene `DELETE` sobre esas tablas desde la
 * migracion 099000 y que es quien puede escribir el asiento de `audit_log` —el
 * de mantenimiento no puede: solo tiene `SELECT`—. Que las dos cosas ocurran en
 * la misma transaccion es lo que garantiza que no se borre nada sin dejar
 * constancia (regla dura 6).
 *
 * ## Reversible
 *
 * `down()` suelta la funcion. No toca ninguna particion ni ningun ancla: revertir
 * esta migracion quita la capacidad de purgar, nunca datos.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        foreach (AuditLogSchema::dropFunctionStatements() as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement(AuditLogSchema::dropFunctionRemovalStatement());
    }
};
