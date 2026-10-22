<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La cuenta de gestion dada de baja se minimiza en sitio al vencer su plazo
 * (bloque 11 de la 2.3.0, tarea 11.3 B, F4; ADR-060, ADR-057 §2 y §3, ADR-051;
 * RL-11, RGPD art. 5.1.e).
 *
 * ## `deactivated_at`
 *
 * Hasta ahora la fecha de la baja solo constaba en el asiento
 * `user.deactivated`. La columna nueva se rellena con el ultimo de esos
 * asientos de cada cuenta inactiva (o con su `updated_at`, si el asiento ya no
 * existe) y desde ahora la escribe la baja. `users_chk_deactivated_has_date`:
 * una cuenta inactiva tiene fecha de baja. No hay reactivacion (ADR-051 §1).
 * `users_stamp_deactivation`, `BEFORE INSERT OR UPDATE`, pone `now()` a una
 * baja que llega sin fecha: el codigo anterior a esta version, que convive con
 * ella durante el despliegue, no la escribe.
 *
 * ## `anonymized_at` y lo que queda de una cuenta minimizada
 *
 * `users_chk_anonymized_is_minimised`: con `anonymized_at`, el nombre es `''`,
 * el correo `anonymized+<uuid>@invalid` (unico y no entregable), la contraseña
 * `'!'` (ningun hash la verifica) y el segundo factor, el recuerdo de sesion,
 * el ultimo acceso y la contraseña temporal, nulos. Se conservan `id`, `uuid`,
 * `locale`, los roles y las fechas: la cuenta sigue siendo la autora de lo que
 * hizo, sin decir ya quien era.
 *
 * ## La barrera en el motor (patron de ADR-057 §2 y §3)
 *
 * - `users_guard_anonymized`, `BEFORE INSERT OR UPDATE`: una fila minimizada
 *   no se modifica (42501), y `anonymized_at` solo lo escribe el propietario
 *   de la tabla, es decir, la funcion de abajo.
 * - `public.effective_compliance_profile_ids()`: el perfil que rige cada
 *   centro. La comparten las dos funciones de minimizar.
 * - `public.management_account_anonymize_batch(p_cutoff date, p_batch int,
 *   p_only bigint[])`, `SECURITY DEFINER`, `search_path` cerrado y
 *   `lock_timeout` propio. Calcula ella misma el corte maximo a partir del
 *   perfil —el mismo que la purga del registro— y rechaza uno posterior con
 *   42501. Solo toca las cuentas de `p_only`, las que conto el plan antes de
 *   purgar (decision del propietario del 22-10-2026: la ejecucion no minimiza
 *   mas de lo que aprobo la simulacion). Solo minimiza cuentas
 *   inactivas, dadas de baja en o antes del corte y de las que no queda ningun
 *   asiento (como actor ni como sujeto) ni ninguna correccion ni resolucion de
 *   incidencia: mientras quede algo suyo, la cuenta se conserva como autora.
 *   Borra sus tokens. `EXECUTE` solo para la aplicacion.
 *
 * ## Despliegue (expand)
 *
 * Todo es nuevo y nulo; el codigo anterior no lo lee. Los dos `CHECK` se
 * añaden `NOT VALID` y se validan en otra transaccion. `down()` se niega si ya
 * hay alguna cuenta minimizada: lo minimizado no se puede devolver.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    private const string CHECK_DEACTIVATED = 'users_chk_deactivated_has_date';

    private const string CHECK_MINIMISED = 'users_chk_anonymized_is_minimised';

    /**
     * El relleno de `deactivated_at`: el ultimo asiento `user.deactivated` de
     * cada cuenta inactiva, o su `updated_at` si ya no esta. Publico para que
     * una prueba lo ejecute sobre filas sembradas.
     */
    public const string BACKFILL_DEACTIVATED_AT = <<<'SQL'
        UPDATE users u
           SET deactivated_at = COALESCE(
                   (SELECT max(a.occurred_at)
                      FROM audit_log a
                     WHERE a.action = 'user.deactivated'
                       AND a.subject_type = 'user'
                       AND a.subject_id = u.id),
                   u.updated_at,
                   u.created_at,
                   now()
               )
         WHERE u.is_active = false
           AND u.deactivated_at IS NULL
        SQL;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS deactivated_at timestamptz(6) NULL');
            DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS anonymized_at timestamptz(6) NULL');

            // Relleno de las bajas que ya existen. `users` son decenas de filas:
            // aqui no hace falta relleno por lotes.
            DB::statement(self::BACKFILL_DEACTIVATED_AT);

            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CHECK_DEACTIVATED);
            DB::statement('ALTER TABLE users ADD CONSTRAINT '.self::CHECK_DEACTIVATED
                .' CHECK (is_active OR deactivated_at IS NOT NULL) NOT VALID');

            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CHECK_MINIMISED);
            DB::statement('ALTER TABLE users ADD CONSTRAINT '.self::CHECK_MINIMISED.' CHECK ('
                .'anonymized_at IS NULL OR ('
                ."name = '' AND email = 'anonymized+' || uuid::text || '@invalid' AND password = '!'"
                .' AND is_active = false'
                .' AND remember_token IS NULL AND last_login_at IS NULL AND temporary_password_expires_at IS NULL'
                .' AND two_factor_secret IS NULL AND two_factor_confirmed_at IS NULL AND two_factor_last_slice IS NULL'
                .')) NOT VALID');

            // Antes que el guardian: si la fila esta congelada, rechazar es lo
            // primero que tiene que pasar, pero sellar la baja no la cambia.
            DB::statement($this->stampFunction());
            DB::statement('DROP TRIGGER IF EXISTS users_stamp_deactivation ON users');
            DB::statement('CREATE TRIGGER users_stamp_deactivation BEFORE INSERT OR UPDATE ON users '
                .'FOR EACH ROW EXECUTE FUNCTION public.users_stamp_deactivation()');

            DB::statement($this->guardFunction());
            DB::statement('DROP TRIGGER IF EXISTS users_guard_anonymized ON users');
            DB::statement('CREATE TRIGGER users_guard_anonymized BEFORE INSERT OR UPDATE ON users '
                .'FOR EACH ROW EXECUTE FUNCTION public.users_guard_anonymized()');

            DB::statement($this->effectiveProfilesFunction());
            DB::statement($this->candidatesFunction());
            DB::statement($this->anonymizeFunction());

            DB::statement('REVOKE ALL ON FUNCTION public.users_stamp_deactivation() FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION public.users_guard_anonymized() FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION public.effective_compliance_profile_ids() FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION public.management_account_retention_candidates(date) FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION public.management_account_retention_candidates(date) TO '
                .$this->applicationRole());
            DB::statement('REVOKE ALL ON FUNCTION public.management_account_anonymize_batch(date, integer, bigint[]) FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION public.management_account_anonymize_batch(date, integer, bigint[]) TO '
                .$this->applicationRole());
        });

        $this->validateConstraint('users', self::CHECK_DEACTIVATED);
        $this->validateConstraint('users', self::CHECK_MINIMISED);
    }

    public function down(): void
    {
        // Antes del primer `ALTER`: lo minimizado no se puede devolver.
        if (DB::table('users')->whereNotNull('anonymized_at')->exists()) {
            throw new RuntimeException(
                'No se puede deshacer: hay cuentas de gestion ya minimizadas por retencion (users.anonymized_at). '
                .'Sus datos no existen y la columna es lo unico que dice que se minimizaron.'
            );
        }

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('DROP FUNCTION IF EXISTS public.management_account_anonymize_batch(date, integer, bigint[])');
            DB::statement('DROP FUNCTION IF EXISTS public.management_account_retention_candidates(date)');
            DB::statement('DROP FUNCTION IF EXISTS public.effective_compliance_profile_ids()');
            DB::statement('DROP TRIGGER IF EXISTS users_guard_anonymized ON users');
            DB::statement('DROP FUNCTION IF EXISTS public.users_guard_anonymized()');
            DB::statement('DROP TRIGGER IF EXISTS users_stamp_deactivation ON users');
            DB::statement('DROP FUNCTION IF EXISTS public.users_stamp_deactivation()');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CHECK_MINIMISED);
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CHECK_DEACTIVATED);
            DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS anonymized_at');
            DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS deactivated_at');
        });
    }

    /**
     * La baja sin fecha la sella el motor (expand): el codigo anterior a esta
     * version da de baja sin escribir `deactivated_at`, y durante el despliegue
     * conviven los dos. La baja de la version nueva la escribe ella con el
     * reloj de la aplicacion, y este *trigger* no la toca.
     */
    private function stampFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.users_stamp_deactivation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            BEGIN
                IF NEW.is_active = false AND NEW.deactivated_at IS NULL THEN
                    NEW.deactivated_at := pg_catalog.now();
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL;
    }

    private function guardFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.users_guard_anonymized()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            DECLARE
                v_owner name;
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.anonymized_at IS NOT NULL THEN
                    RAISE EXCEPTION 'users_guard_anonymized: la cuenta % esta minimizada por retencion y no se modifica (ADR-060)', OLD.uuid
                        USING ERRCODE = '42501';
                END IF;

                IF (TG_OP = 'INSERT' AND NEW.anonymized_at IS NOT NULL)
                   OR (TG_OP = 'UPDATE' AND NEW.anonymized_at IS DISTINCT FROM OLD.anonymized_at) THEN
                    SELECT pg_catalog.pg_get_userbyid(c.relowner) INTO v_owner
                      FROM pg_catalog.pg_class c
                     WHERE c.oid = TG_RELID;

                    IF current_user <> v_owner THEN
                        RAISE EXCEPTION 'users_guard_anonymized: anonymized_at solo lo escribe management_account_anonymize_batch (ADR-060)'
                            USING ERRCODE = '42501';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL;
    }

    /**
     * El perfil de cumplimiento que rige cada centro —el suyo o el de por
     * defecto— y, sin centros, el de por defecto. La usan las dos funciones de
     * minimizar de la 2.3.0 para calcular sus años; la purga del registro
     * (`work_record_purge_batch`, 14-10-2026) lleva la misma consulta dentro y
     * no se redefine aqui. Sin `SECURITY DEFINER` ni `EXECUTE` para nadie: solo
     * la llaman funciones del mismo propietario.
     */
    private function effectiveProfilesFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.effective_compliance_profile_ids()
            RETURNS TABLE (profile_id bigint)
            LANGUAGE sql
            STABLE
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
                SELECT COALESCE(
                           s.compliance_profile_id,
                           (SELECT d.id FROM public.compliance_profiles d WHERE d.is_default ORDER BY d.id LIMIT 1)
                       )
                  FROM public.sites s
                UNION ALL
                SELECT d.id
                  FROM public.compliance_profiles d
                 WHERE d.is_default
                   AND NOT EXISTS (SELECT 1 FROM public.sites)
            $kronoqr$
            SQL;
    }

    /**
     * Las cuentas que se minimizarian con ese corte, en un solo sitio: la usan
     * la funcion de minimizar y la simulacion de `compliance:apply-retention`,
     * que asi no pueden contar cosas distintas. Sin `SECURITY DEFINER`: solo
     * lee tablas que la aplicacion ya lee, y el corte lo valida quien minimiza.
     */
    private function candidatesFunction(): string
    {
        return <<<'SQL'
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
                   )
            $kronoqr$
            SQL;
    }

    private function anonymizeFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.management_account_anonymize_batch(p_cutoff date, p_batch integer, p_only bigint[])
            RETURNS TABLE (user_id bigint, user_uuid uuid, deactivated_on date, tokens bigint)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            SET lock_timeout = '5s'
            AS $kronoqr$
            DECLARE
                v_today   date := (pg_catalog.now() AT TIME ZONE 'UTC')::date;
                v_years   integer;
                v_maximum date;
                v_account record;
            BEGIN
                IF p_cutoff IS NULL OR p_batch IS NULL OR p_batch < 1 OR p_batch > 10000 THEN
                    RAISE EXCEPTION 'management_account_anonymize_batch: corte % o lote % fuera de rango', p_cutoff, p_batch
                        USING ERRCODE = '22023';
                END IF;

                IF p_only IS NULL THEN
                    RAISE EXCEPTION 'management_account_anonymize_batch: falta la lista de cuentas que conto el plan'
                        USING ERRCODE = '22023';
                END IF;

                -- Los mismos años que la purga del registro (ADR-057 §3): el
                -- perfil de cada centro, o el de por defecto, sin bajar del
                -- suelo de su jurisdiccion. Entre centros, el mas largo.
                SELECT max(GREATEST(p.retention_years, public.work_record_retention_floor(p.jurisdiction)))
                  INTO v_years
                  FROM public.effective_compliance_profile_ids() effective
                  JOIN public.compliance_profiles p ON p.id = effective.profile_id;

                IF v_years IS NULL THEN
                    RAISE EXCEPTION 'management_account_anonymize_batch: no hay perfil de cumplimiento con el que calcular el corte'
                        USING ERRCODE = 'P0002';
                END IF;

                v_maximum := public.work_record_purge_max_cutoff(v_today, v_years);

                IF p_cutoff > v_maximum THEN
                    RAISE EXCEPTION 'management_account_anonymize_batch: el corte % es posterior al admisible % (% años desde el % UTC; ADR-060)',
                        p_cutoff, v_maximum, v_years, v_today
                        USING ERRCODE = '42501';
                END IF;

                FOR v_account IN
                    SELECT u.id, u.uuid, (u.deactivated_at AT TIME ZONE 'UTC')::date AS deactivated_on
                      FROM public.users u
                     WHERE u.id IN (SELECT c.id FROM public.management_account_retention_candidates(p_cutoff) c)
                       -- Solo las que conto el plan de esta pasada, antes de
                       -- purgar: lo que libera la purga espera a la siguiente.
                       AND u.id = ANY (p_only)
                       -- Se vuelve a mirar con la fila tomada: otra pasada pudo minimizarla.
                       AND u.is_active = false
                       AND u.anonymized_at IS NULL
                     ORDER BY u.id
                     LIMIT p_batch
                       FOR UPDATE OF u
                LOOP
                    DELETE FROM public.personal_access_tokens t
                     WHERE t.tokenable_type = 'App\Modules\Identity\Infrastructure\Persistence\User'
                       AND t.tokenable_id = v_account.id;
                    GET DIAGNOSTICS tokens = ROW_COUNT;

                    UPDATE public.users u
                       SET name = '',
                           email = 'anonymized+' || u.uuid::text || '@invalid',
                           password = '!',
                           remember_token = NULL,
                           last_login_at = NULL,
                           temporary_password_expires_at = NULL,
                           two_factor_secret = NULL,
                           two_factor_confirmed_at = NULL,
                           two_factor_last_slice = NULL,
                           anonymized_at = pg_catalog.now(),
                           updated_at = pg_catalog.now()
                     WHERE u.id = v_account.id;

                    user_id := v_account.id;
                    user_uuid := v_account.uuid;
                    deactivated_on := v_account.deactivated_on;
                    RETURN NEXT;
                END LOOP;
            END;
            $kronoqr$
            SQL;
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
