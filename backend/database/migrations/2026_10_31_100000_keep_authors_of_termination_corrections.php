<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La cuenta que corrigio una fecha de cese se conserva como autora mientras
 * quede su correccion (ADR-051, ADR-060, ADR-062 §7; RN-13).
 *
 * `management_account_retention_candidates` deja fuera de la minimizacion a
 * la cuenta de la que queda algo: un asiento, una correccion de tramo o la
 * resolucion de una incidencia. `employment_period_corrections` es posterior
 * (`2026_10_30_100000`) y la funcion no sabia de ella. Hasta ahora no hacia
 * falta, porque el asiento `employee.termination_date_corrected` de la misma
 * cuenta la protegia mientras existiera. Desde que la ficha de `rrhh+` ensena
 * el historico de las correcciones con su autor (`EmploymentPeriodRecord`,
 * esquema `CorrectionAuthor`, nombre no vacio), la correccion tiene que
 * protegerla por si misma: la ficha de una persona readmitida puede durar mas
 * que los asientos de la correccion de su primer periodo.
 *
 * ## Despliegue (expand)
 *
 * Solo redefine la funcion, con la misma firma y el mismo tipo de retorno.
 * `CREATE OR REPLACE` conserva el propietario y el `EXECUTE` de la aplicacion.
 * El codigo que la llama no cambia. `down()` vuelve **byte a byte** a la
 * definicion de `2026_10_21_100100` (`MigrationsRoundTripTest`): el bloque
 * nuevo se pega al final de la linea de las incidencias, no en una linea
 * propia. La busqueda va por `employment_period_corrections_user_index`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();
            DB::statement($this->candidatesFunction(true));
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();
            DB::statement($this->candidatesFunction(false));
        });
    }

    private function candidatesFunction(bool $withTerminationCorrections): string
    {
        $authors = $withTerminationCorrections ? <<<'SQL'

                   -- La correccion de la fecha de cese de un periodo (RN-27).
                   AND NOT EXISTS (
                       SELECT 1 FROM public.employment_period_corrections pc WHERE pc.corrected_by_user_id = u.id
                   )
            SQL : '';

        return str_replace('__TERMINATION_CORRECTIONS__', $authors, <<<'SQL'
            CREATE OR REPLACE FUNCTION public.management_account_retention_candidates(p_cutoff date)
            RETURNS TABLE (id bigint)
            LANGUAGE sql
            STABLE
            STRICT
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
                SELECT u.id
                  FROM public.users u
                 WHERE u.is_active = false
                   AND u.anonymized_at IS NULL
                   AND u.deactivated_at IS NOT NULL
                   AND (u.deactivated_at AT TIME ZONE 'UTC')::date <= p_cutoff
                   -- Mientras quede algo suyo, la cuenta se conserva como autora
                   -- (ADR-051, RF-ID-10): un asiento como actor o como sujeto,
                   -- una correccion o la resolucion de una incidencia.
                   AND NOT EXISTS (
                       SELECT 1 FROM public.audit_log a
                        WHERE a.actor_type = 'user' AND a.actor_id = u.id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM public.audit_log a
                        WHERE a.subject_type = 'user' AND a.subject_id = u.id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM public.shift_corrections sc WHERE sc.performed_by_user_id = u.id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM public.incidents i WHERE i.resolved_by_user_id = u.id
                   )__TERMINATION_CORRECTIONS__
            $kronoqr$
            SQL);
    }
};
