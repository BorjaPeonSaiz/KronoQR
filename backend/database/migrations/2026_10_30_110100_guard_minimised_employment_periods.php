<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una ficha minimizada no recibe periodos ni correcciones de su fecha de cese,
 * tambien en el motor (ADR-060, ADR-062 §6 y §7; RN-27, RL-11).
 *
 * El dominio ya lo rechaza (`Employee::assertAcceptsPersonnelRecords()`, `409`
 * `employee-record-minimised`). Esto es la misma regla para quien no pase por
 * el, como `employees_guard_anonymized_dependents` hace con contratos y
 * ausencias desde `2026_10_21_100300`:
 *
 * - `employment_periods_guard_anonymized_employee`, `BEFORE INSERT OR UPDATE`
 *   en `employment_periods`, con la misma funcion que contratos y ausencias:
 *   ni periodo nuevo (readmision) ni cambio de uno existente (correccion o
 *   baja) de una ficha minimizada. Sobre toda la fila y no solo `employee_id`:
 *   la correccion cambia `terminated_on`.
 * - `employment_period_corrections_guard_anonymized`, `BEFORE INSERT` en
 *   `employment_period_corrections`: la ficha se alcanza por el periodo. Solo
 *   `INSERT`: la aplicacion no tiene `UPDATE` en esa tabla, y la unica que la
 *   actualiza es `personnel_record_anonymize_batch`, que sustituye el motivo
 *   al minimizar y no puede encontrarse con su propia guarda.
 *
 * Las dos responden `42501`, como el resto de barreras de ADR-057.
 *
 * ## Despliegue (expand)
 *
 * Dos disparadores y una funcion nuevos; ningun dato cambia. `CREATE TRIGGER`
 * toma un candado breve sobre las dos tablas, con `lock_timeout` bajo. `down()`
 * los retira.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('DROP TRIGGER IF EXISTS employment_periods_guard_anonymized_employee ON employment_periods');
            DB::statement('CREATE TRIGGER employment_periods_guard_anonymized_employee BEFORE INSERT OR UPDATE ON employment_periods'
                .' FOR EACH ROW EXECUTE FUNCTION public.employees_guard_anonymized_dependents()');

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.employment_period_corrections_guard_anonymized()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                BEGIN
                    IF EXISTS (
                        SELECT 1
                          FROM public.employment_periods p
                          JOIN public.employees e ON e.id = p.employee_id
                         WHERE p.id = NEW.employment_period_id
                           AND e.anonymized_at IS NOT NULL
                    ) THEN
                        RAISE EXCEPTION 'employment_period_corrections: el periodo % es de una ficha minimizada por retencion y no admite correcciones (ADR-060)',
                            NEW.employment_period_id
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NEW;
                END;
                $kronoqr$
                SQL);

            DB::statement('REVOKE ALL ON FUNCTION public.employment_period_corrections_guard_anonymized() FROM PUBLIC');

            DB::statement('DROP TRIGGER IF EXISTS employment_period_corrections_guard_anonymized ON employment_period_corrections');
            DB::statement('CREATE TRIGGER employment_period_corrections_guard_anonymized BEFORE INSERT ON employment_period_corrections'
                .' FOR EACH ROW EXECUTE FUNCTION public.employment_period_corrections_guard_anonymized()');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('DROP TRIGGER IF EXISTS employment_period_corrections_guard_anonymized ON employment_period_corrections');
            DB::statement('DROP FUNCTION IF EXISTS public.employment_period_corrections_guard_anonymized()');
            DB::statement('DROP TRIGGER IF EXISTS employment_periods_guard_anonymized_employee ON employment_periods');
        });
    }
};
