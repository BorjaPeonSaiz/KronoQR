<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La ficha de una persona dada de baja se minimiza en sitio al vencer su plazo
 * (bloque 11 de la 2.3.0, tarea 11.3 A, F4; ADR-060, ADR-057 §2 y §3; RL-11,
 * RL-02, RL-08, RGPD art. 5.1.e).
 *
 * ## Por que en sitio y no un `DELETE`
 *
 * `credentials`, `shift_entries`, `scan_events`, `daily_totals`, `incidents`,
 * `employment_contracts` y `absences` la apuntan con `RESTRICT`, y la ficha
 * tiene que seguir existiendo como destino de esas claves. Lo que deja de
 * existir es lo que identifica a la persona.
 *
 * ## `anonymized_at` y lo que queda
 *
 * `employees_chk_anonymized_is_minimised`: con `anonymized_at`, nombre y
 * apellidos `''`, correo, documento, foto y todo lo del PIN nulos, y el codigo
 * de empleado sustituido por el uuid sin guiones (ya no es la mitad de ninguna
 * credencial: el portal da el rechazo generico con el codigo antiguo). Se
 * conservan `uuid`, centro, departamento, estado y fechas de alta y cese.
 *
 * ## La barrera en el motor (patron de ADR-057 §2 y §3)
 *
 * - `employees_guard_anonymized`, `BEFORE INSERT OR UPDATE`: una ficha
 *   minimizada no se modifica (42501), y `anonymized_at` solo lo escribe el
 *   propietario de la tabla, es decir, la funcion de abajo.
 * - `public.personnel_record_retention_candidates(p_cutoff date)`: las fichas
 *   que se minimizarian con ese corte. La usan la funcion y la simulacion.
 * - `public.personnel_record_anonymize_batch(p_cutoff date, p_batch int)`,
 *   `SECURITY DEFINER`, `search_path` cerrado y `lock_timeout` propio. Calcula
 *   su corte maximo con `personnel_record_retention_years` del perfil (el mas
 *   largo entre centros) y rechaza uno posterior con 42501. Solo minimiza una
 *   ficha de baja cuyo cese es anterior o igual al corte, de la que no queda
 *   ninguna fila en el registro horario y cuya particion de `audit_log` del
 *   año del cese ya se solto. Borra sus contratos, sus ausencias y sus sesiones
 *   del portal, y devuelve lo borrado y la ruta de la foto, que la aplicacion
 *   borra despues del `COMMIT`. `EXECUTE` solo para la aplicacion.
 * - La aplicacion pierde `DELETE` sobre `employment_contracts` y `absences`:
 *   ningun codigo los borra (comprobado), y desde ahora solo los borra esta
 *   funcion.
 *
 * ## Despliegue (expand)
 *
 * Todo es nuevo y nulo. El `CHECK` se añade `NOT VALID` y se valida en otra
 * transaccion. `down()` se niega si ya hay alguna ficha minimizada.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    private const string CHECK_MINIMISED = 'employees_chk_anonymized_is_minimised';

    /**
     * La clase con la que Sanctum guarda las sesiones del portal en
     * `personal_access_tokens.tokenable_type`.
     */
    private const string PORTAL_TOKENABLE = 'App\Modules\Workforce\Infrastructure\Persistence\Employee';

    /**
     * Las dos tablas que pierden `DELETE` para la aplicacion.
     *
     * @var list<string>
     */
    private const array NO_DELETE = ['employment_contracts', 'absences'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE employees ADD COLUMN IF NOT EXISTS anonymized_at timestamptz(6) NULL');

            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS '.self::CHECK_MINIMISED);
            DB::statement('ALTER TABLE employees ADD CONSTRAINT '.self::CHECK_MINIMISED.' CHECK ('
                .'anonymized_at IS NULL OR ('
                ."status = 'terminated' AND first_name = '' AND last_name = ''"
                ." AND employee_code = replace(uuid::text, '-', '')"
                .' AND email IS NULL AND national_id_hash IS NULL AND photo_path IS NULL'
                .' AND pin_hash IS NULL AND pin_issued_at IS NULL AND pin_delivered_at IS NULL'
                .' AND pin_delivered_by_user_id IS NULL AND pin_length IS NULL'
                .')) NOT VALID');

            DB::statement($this->guardFunction());
            DB::statement('DROP TRIGGER IF EXISTS employees_guard_anonymized ON employees');
            DB::statement('CREATE TRIGGER employees_guard_anonymized BEFORE INSERT OR UPDATE ON employees '
                .'FOR EACH ROW EXECUTE FUNCTION public.employees_guard_anonymized()');

            DB::statement($this->candidatesFunction());
            DB::statement($this->anonymizeFunction());

            $application = $this->applicationRole();

            DB::statement('REVOKE ALL ON FUNCTION public.employees_guard_anonymized() FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION public.personnel_record_retention_candidates(date) FROM PUBLIC');
            DB::statement('REVOKE ALL ON FUNCTION public.personnel_record_anonymize_batch(date, integer) FROM PUBLIC');
            DB::statement('GRANT EXECUTE ON FUNCTION public.personnel_record_retention_candidates(date) TO '.$application);
            DB::statement('GRANT EXECUTE ON FUNCTION public.personnel_record_anonymize_batch(date, integer) TO '.$application);

            foreach (self::NO_DELETE as $table) {
                DB::statement('REVOKE DELETE ON TABLE '.$table.' FROM '.$application);
            }
        });

        $this->validateConstraint('employees', self::CHECK_MINIMISED);
    }

    public function down(): void
    {
        // Antes del primer `ALTER`: lo minimizado no se puede devolver.
        if (DB::table('employees')->whereNotNull('anonymized_at')->exists()) {
            throw new RuntimeException(
                'No se puede deshacer: hay fichas ya minimizadas por retencion (employees.anonymized_at). '
                .'Sus datos no existen y la columna es lo unico que dice que se minimizaron.'
            );
        }

        DB::transaction(function (): void {
            $this->limitLockWait();

            $application = $this->applicationRole();

            foreach (self::NO_DELETE as $table) {
                DB::statement('GRANT DELETE ON TABLE '.$table.' TO '.$application);
            }

            DB::statement('DROP FUNCTION IF EXISTS public.personnel_record_anonymize_batch(date, integer)');
            DB::statement('DROP FUNCTION IF EXISTS public.personnel_record_retention_candidates(date)');
            DB::statement('DROP TRIGGER IF EXISTS employees_guard_anonymized ON employees');
            DB::statement('DROP FUNCTION IF EXISTS public.employees_guard_anonymized()');
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS '.self::CHECK_MINIMISED);
            DB::statement('ALTER TABLE employees DROP COLUMN IF EXISTS anonymized_at');
        });
    }

    private function guardFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.employees_guard_anonymized()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            DECLARE
                v_owner name;
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.anonymized_at IS NOT NULL THEN
                    RAISE EXCEPTION 'employees_guard_anonymized: la ficha % esta minimizada por retencion y no se modifica (ADR-060)', OLD.uuid
                        USING ERRCODE = '42501';
                END IF;

                IF (TG_OP = 'INSERT' AND NEW.anonymized_at IS NOT NULL)
                   OR (TG_OP = 'UPDATE' AND NEW.anonymized_at IS DISTINCT FROM OLD.anonymized_at) THEN
                    SELECT pg_catalog.pg_get_userbyid(c.relowner) INTO v_owner
                      FROM pg_catalog.pg_class c
                     WHERE c.oid = TG_RELID;

                    IF current_user <> v_owner THEN
                        RAISE EXCEPTION 'employees_guard_anonymized: anonymized_at solo lo escribe personnel_record_anonymize_batch (ADR-060)'
                            USING ERRCODE = '42501';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL;
    }

    private function candidatesFunction(): string
    {
        return <<<'SQL'
            CREATE OR REPLACE FUNCTION public.personnel_record_retention_candidates(p_cutoff date)
            RETURNS TABLE (id bigint)
            LANGUAGE sql
            STABLE
            STRICT
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
                SELECT e.id
                  FROM public.employees e
                 WHERE e.status = 'terminated'
                   AND e.anonymized_at IS NULL
                   AND e.terminated_at IS NOT NULL
                   AND e.terminated_at <= p_cutoff
                   -- Nada suyo en el registro horario: mientras quede, la ficha
                   -- es el destino de esas filas y se conserva entera.
                   AND NOT EXISTS (SELECT 1 FROM public.shift_entries s WHERE s.employee_id = e.id)
                   AND NOT EXISTS (
                       SELECT 1 FROM public.scan_events s
                        WHERE s.employee_id = e.id OR s.claimed_employee_id = e.id
                   )
                   AND NOT EXISTS (SELECT 1 FROM public.daily_totals t WHERE t.employee_id = e.id)
                   AND NOT EXISTS (SELECT 1 FROM public.incidents i WHERE i.employee_id = e.id)
                   AND NOT EXISTS (
                       SELECT 1 FROM public.discarded_scan_reports d WHERE d.owner_employee_id = e.id
                   )
                   -- La particion de `audit_log` del año del cese ya se solto:
                   -- sellada en `audit_chain_anchors`, o ya no esta en el catalogo.
                   AND (
                       EXISTS (
                           SELECT 1 FROM public.audit_chain_anchors a
                            WHERE a.partition_year = pg_catalog.date_part('year', e.terminated_at)::integer
                       )
                       OR NOT EXISTS (
                           SELECT 1
                             FROM pg_catalog.pg_inherits inh
                             JOIN pg_catalog.pg_class parent ON parent.oid = inh.inhparent
                             JOIN pg_catalog.pg_class child ON child.oid = inh.inhrelid
                             JOIN pg_catalog.pg_namespace ns ON ns.oid = parent.relnamespace
                            WHERE ns.nspname = 'public'
                              AND parent.relname = 'audit_log'
                              AND child.relname = 'audit_log_' || pg_catalog.date_part('year', e.terminated_at)::integer::text
                       )
                   )
            $kronoqr$
            SQL;
    }

    private function anonymizeFunction(): string
    {
        return str_replace('__PORTAL_TOKENABLE__', self::PORTAL_TOKENABLE, <<<'SQL'
            CREATE OR REPLACE FUNCTION public.personnel_record_anonymize_batch(p_cutoff date, p_batch integer)
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

                -- Los años de la ficha de cada centro: su perfil, o el de por
                -- defecto. Entre centros, el mas largo.
                SELECT max(p.personnel_record_retention_years)
                  INTO v_years
                  FROM (
                        SELECT COALESCE(
                                   s.compliance_profile_id,
                                   (SELECT d.id FROM public.compliance_profiles d WHERE d.is_default ORDER BY d.id LIMIT 1)
                               ) AS profile_id
                          FROM public.sites s
                        UNION ALL
                        SELECT d.id
                          FROM public.compliance_profiles d
                         WHERE d.is_default
                           AND NOT EXISTS (SELECT 1 FROM public.sites)
                       ) effective
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

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
