<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La minimizacion de una ficha tambien retira el motivo de las correcciones de
 * su fecha de cese (ADR-060, ADR-062 §7; RL-11, RL-08, RGPD art. 5.1.e).
 *
 * `employment_period_corrections.reason` es texto libre de 10 a 1 000
 * caracteres que escribe RRHH al corregir un cese (RN-27), y puede nombrar a la
 * persona o contar por que se fue. `personnel_record_anonymize_batch` borraba
 * contratos, ausencias y sesiones y sustituia el motivo de revocar sus tarjetas,
 * pero no sabia de esta tabla, que es posterior: el motivo sobrevivia a la
 * minimizacion. Desde esta migracion se sustituye por un texto fijo, como el de
 * las tarjetas (el `CHECK` exige motivo: se sustituye, no se vacia). Las fechas
 * de la correccion, quien la hizo y cuando se conservan: son registro (RN-13).
 *
 * ## Despliegue (expand)
 *
 * Solo redefine la funcion, con la misma firma y el mismo tipo de retorno: el
 * codigo que la llama no cambia. `down()` vuelve a la definicion anterior; lo
 * que ya se sustituyo no se devuelve, porque el original no existe.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    /**
     * La clase con la que Sanctum guarda las sesiones del portal, como en la
     * migracion que creo la funcion.
     */
    private const string PORTAL_TOKENABLE = 'App\Modules\Workforce\Infrastructure\Persistence\Employee';

    /** El de las tarjetas, sin cambios (`2026_10_21_100300`). */
    private const string CREDENTIAL_REASON = 'Motivo retirado al minimizar la ficha (ADR-060)';

    /**
     * El motivo que sustituye al de cada correccion de la fecha de cese de la
     * ficha minimizada. Publico para las pruebas.
     */
    public const string CORRECTION_REASON = 'Motivo retirado al minimizar la ficha (ADR-060, ADR-062)';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();
            DB::statement($this->anonymizeFunction(true));
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();
            DB::statement($this->anonymizeFunction(false));
        });
    }

    /**
     * `CREATE OR REPLACE` conserva el propietario, `SECURITY DEFINER` va en la
     * definicion y los permisos de `EXECUTE` no cambian con la sustitucion.
     */
    private function anonymizeFunction(bool $withCorrections): string
    {
        $corrections = $withCorrections ? <<<'SQL'

                    -- El motivo de corregir la fecha de cese de sus periodos
                    -- (RN-27) tambien es texto libre y pudo nombrar a alguien.
                    -- Las fechas, quien y cuando se conservan (RN-13).
                    UPDATE public.employment_period_corrections pc
                       SET reason = '__CORRECTION_REASON__'
                      FROM public.employment_periods p
                     WHERE p.id = pc.employment_period_id
                       AND p.employee_id = v_record.id
                       AND pc.reason <> '__CORRECTION_REASON__';
            SQL : '';

        return str_replace(
            ['__CORRECTIONS__', '__PORTAL_TOKENABLE__', '__CREDENTIAL_REASON__', '__CORRECTION_REASON__'],
            [$corrections, self::PORTAL_TOKENABLE, self::CREDENTIAL_REASON, self::CORRECTION_REASON],
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.personnel_record_anonymize_batch(p_cutoff date, p_batch integer, p_only bigint[])
                RETURNS TABLE (employee_uuid uuid, terminated_on date, contracts bigint, absences bigint, tokens bigint, photo_path text)
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, pg_temp
                SET lock_timeout = '5s'
                AS $kronoqr$
                DECLARE
                    v_today   date := (pg_catalog.now() AT TIME ZONE 'UTC')::date;
                    v_years   integer;
                    v_maximum date;
                    v_record  record;
                BEGIN
                    IF p_cutoff IS NULL OR p_batch IS NULL OR p_batch < 1 OR p_batch > 10000 THEN
                        RAISE EXCEPTION 'personnel_record_anonymize_batch: corte % o lote % fuera de rango', p_cutoff, p_batch
                            USING ERRCODE = '22023';
                    END IF;

                    IF p_only IS NULL THEN
                        RAISE EXCEPTION 'personnel_record_anonymize_batch: falta la lista de fichas que conto el plan'
                            USING ERRCODE = '22023';
                    END IF;

                    -- Los años de la ficha de cada centro: su perfil, o el de por
                    -- defecto. Entre centros, el mas largo.
                    SELECT max(p.personnel_record_retention_years)
                      INTO v_years
                      FROM public.effective_compliance_profile_ids() effective
                      JOIN public.compliance_profiles p ON p.id = effective.profile_id;

                    IF v_years IS NULL THEN
                        RAISE EXCEPTION 'personnel_record_anonymize_batch: no hay perfil de cumplimiento con el que calcular el corte'
                            USING ERRCODE = 'P0002';
                    END IF;

                    v_maximum := public.work_record_purge_max_cutoff(v_today, v_years);

                    IF p_cutoff > v_maximum THEN
                        RAISE EXCEPTION 'personnel_record_anonymize_batch: el corte % es posterior al admisible % (% años desde el % UTC; ADR-060)',
                            p_cutoff, v_maximum, v_years, v_today
                            USING ERRCODE = '42501';
                    END IF;

                    FOR v_record IN
                        SELECT e.id, e.uuid, e.terminated_at, e.photo_path
                          FROM public.employees e
                         WHERE e.id IN (SELECT c.id FROM public.personnel_record_retention_candidates(p_cutoff) c)
                           -- Solo las que conto el plan de esta pasada, antes de
                           -- purgar: lo que libera la purga espera a la siguiente.
                           AND e.id = ANY (p_only)
                           -- Se vuelve a mirar con la fila tomada: otra pasada pudo minimizarla.
                           AND e.status = 'terminated'
                           AND e.anonymized_at IS NULL
                         ORDER BY e.id
                         LIMIT p_batch
                           FOR UPDATE OF e
                    LOOP
                        DELETE FROM public.employment_contracts c WHERE c.employee_id = v_record.id;
                        GET DIAGNOSTICS contracts = ROW_COUNT;

                        DELETE FROM public.absences a WHERE a.employee_id = v_record.id;
                        GET DIAGNOSTICS absences = ROW_COUNT;

                        DELETE FROM public.personal_access_tokens t
                         WHERE t.tokenable_type = '__PORTAL_TOKENABLE__'
                           AND t.tokenable_id = v_record.id;
                        GET DIAGNOSTICS tokens = ROW_COUNT;

                        -- El motivo de revocar sus tarjetas es texto libre y pudo
                        -- nombrar a alguien. El CHECK exige motivo en una revocada:
                        -- se sustituye, no se vacia.
                        UPDATE public.credentials c
                           SET revoked_reason = '__CREDENTIAL_REASON__'
                         WHERE c.employee_id = v_record.id
                           AND c.revoked_reason IS NOT NULL
                           AND c.revoked_reason <> '__CREDENTIAL_REASON__';
                __CORRECTIONS__

                        UPDATE public.employees e
                           SET first_name = '',
                               last_name = '',
                               employee_code = replace(e.uuid::text, '-', ''),
                               email = NULL,
                               national_id_hash = NULL,
                               photo_path = NULL,
                               pin_hash = NULL,
                               pin_issued_at = NULL,
                               pin_delivered_at = NULL,
                               pin_delivered_by_user_id = NULL,
                               pin_length = NULL,
                               anonymized_at = pg_catalog.now(),
                               updated_at = pg_catalog.now()
                         WHERE e.id = v_record.id;

                        employee_uuid := v_record.uuid;
                        terminated_on := v_record.terminated_at;
                        photo_path := v_record.photo_path;
                        RETURN NEXT;
                    END LOOP;
                END;
                $kronoqr$
                SQL);
    }
};
