<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los años de la ficha de personal, en el perfil de cumplimiento (bloque 11 de
 * la 2.3.0, tarea 11.3 A; ADR-060, regla dura 14, RL-11).
 *
 * `personnel_record_retention_years`: cuantos años se conserva la ficha de una
 * persona dada de baja antes de minimizarla. `smallint NOT NULL DEFAULT 4`, con
 * `CHECK` entre 1 y 30. Es configuracion del cliente como los demas plazos del
 * perfil, y la lee la funcion `personnel_record_anonymize_batch` para calcular
 * su propio corte maximo.
 *
 * ## Expand
 *
 * La columna nace con valor de serie, asi que el codigo anterior, que no la
 * nombra, sigue insertando y actualizando el perfil igual. El `CHECK` se añade
 * `NOT VALID` y se valida en otra transaccion.
 *
 * ## El privilegio de columna (ADR-057 §7)
 *
 * `fichaje_app` solo actualiza las columnas del perfil que el panel edita
 * (`2026_10_13_100100`). Esta entra en la lista: el panel la edita. La misma
 * lista vive en `infra/scripts/lib/` para `update.sh`, y la actualiza devops.
 *
 * ## Reversible
 *
 * `down()` retira el privilegio de columna y suelta `CHECK` y columna. No hay
 * datos que perder fuera del propio plazo, que vuelve a ser el de serie.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    private const string CHECK = 'compliance_profiles_chk_personnel_record_retention_years';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE compliance_profiles ADD COLUMN IF NOT EXISTS '
                .'personnel_record_retention_years smallint NOT NULL DEFAULT 4');
            DB::statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('ALTER TABLE compliance_profiles ADD CONSTRAINT '.self::CHECK
                .' CHECK (personnel_record_retention_years BETWEEN 1 AND 30) NOT VALID');
            DB::statement('GRANT UPDATE (personnel_record_retention_years) ON TABLE compliance_profiles TO '
                .$this->applicationRole());
        });

        $this->validateConstraint('compliance_profiles', self::CHECK);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('REVOKE UPDATE (personnel_record_retention_years) ON TABLE compliance_profiles FROM '
                .$this->applicationRole());
            DB::statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('ALTER TABLE compliance_profiles DROP COLUMN IF EXISTS personnel_record_retention_years');
        });
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
