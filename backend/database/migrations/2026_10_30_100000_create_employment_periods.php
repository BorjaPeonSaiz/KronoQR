<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los periodos de alta de cada persona y la correccion de su fecha de cese
 * (RN-27, RN-14, RN-13, RL-04; ADR-062 §1, §2 y §7, que enmienda ADR-046).
 *
 * ## Lo que crea
 *
 * - **`employment_periods`**: la historia laboral. Una fila por periodo de alta,
 *   con `hired_on`, `terminated_on` (nula sin baja; con fecha y `offboarded_at`
 *   nulo, baja programada) y `offboarded_at` (el instante en que la baja se hizo
 *   efectiva). Dos periodos de una persona no se solapan
 *   (`employment_periods_no_overlap`, la tecnica de RN-02) y como mucho uno no
 *   tiene la baja efectiva (`one_open_employment_period`).
 * - **`employment_period_corrections`**: la traza solo-append de la correccion
 *   de la fecha de cese, con la fecha anterior, la nueva, el motivo, quien y
 *   cuando. El asiento de `audit_log` no lleva el motivo; esta tabla si.
 *
 * ## `employees` es la proyeccion del periodo en curso
 *
 * `hired_at`, `terminated_at` y `status` se quedan, y dicen lo mismo que el
 * periodo de `hired_on` mas reciente. Las escribe el repositorio en la misma
 * transaccion que el periodo. `employees_current_period_matches`, un disparador
 * de restriccion diferido sobre las dos tablas, falla el `COMMIT` si no
 * coinciden: una escritura por fuera del repositorio no deja una ficha que dice
 * una cosa y una historia que dice otra.
 *
 * ## Las barreras en el motor
 *
 * - `employees_guard_employment_transition`, `BEFORE UPDATE OF status,
 *   hired_at, terminated_at ON employees`: solo las transiciones de la tabla de
 *   ADR-062 §2. Es el disparador que ADR-046 descarto mientras `terminated` era
 *   final; la readmision abre ese camino y desde ahora la unica salida de
 *   `terminated` es la readmision, con una fecha de alta posterior al cese.
 *   No compara con «hoy»: la base no conoce la zona del centro (regla dura 3).
 * - `employment_periods_guard`, `BEFORE UPDATE ON employment_periods`: solo
 *   cambian `terminated_on` y `offboarded_at`; `offboarded_at` no se reescribe
 *   una vez escrito, y un periodo con baja efectiva no pierde su fecha de cese.
 * - El rol de la aplicacion no tiene `DELETE` en ninguna de las dos tablas, ni
 *   `UPDATE` en la de correcciones, y en los periodos solo
 *   `UPDATE (terminated_on, offboarded_at)` (como ADR-057).
 *
 * ## Relleno
 *
 * Un periodo por ficha: `hired_on = hired_at`, `terminated_on = terminated_at`
 * y, para una baja, `offboarded_at` = el `occurred_at` del ultimo asiento
 * `employee.offboarded` de esa persona o, sin asiento, el `updated_at` de la
 * ficha. Las bajas de la 2.1.0 con cese futuro se rellenan tal cual, como bajas
 * efectivas (ADR-062 §1). El `uuid` del relleno es `gen_random_uuid()` (v4):
 * PostgreSQL 17 no genera v7, y el identificador es publico pero no ordena nada.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos tablas nuevas, su relleno y tres disparadores. Ninguna columna cambia. | El codigo de esta version escribe periodo y proyeccion juntos. |
 * | **2 (migrate)** | *No aplica*: el relleno va en esta misma migracion. | — |
 * | **3 (contract)** | *No hay*: las columnas de `employees` se quedan como proyeccion (ADR-062 §1). | — |
 *
 * Todo en una transaccion con `lock_timeout` bajo: `CREATE TRIGGER` toma un
 * candado breve sobre `employees` y el relleno solo lee. `down()` se niega si
 * alguna persona tiene mas de un periodo o hay alguna correccion: bajar
 * perderia historia (regla dura 5).
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    public const string PERIODS = 'employment_periods';

    public const string CORRECTIONS = 'employment_period_corrections';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            $this->createPeriods();
            $this->createCorrections();
            $this->backfill();

            foreach ($this->functions() as $function) {
                DB::statement($function);
            }

            foreach ($this->triggers() as $statement) {
                DB::statement($statement);
            }

            $this->restrictApplication();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            /** @var object{corrections: int|string, rehired: int|string}|null $history */
            $history = DB::selectOne(
                'SELECT (SELECT count(*) FROM '.self::CORRECTIONS.') AS corrections,'
                .' (SELECT count(*) FROM (SELECT employee_id FROM '.self::PERIODS
                .' GROUP BY employee_id HAVING count(*) > 1) AS rehired) AS rehired',
            );

            if ($history !== null && ((int) $history->corrections > 0 || (int) $history->rehired > 0)) {
                throw new RuntimeException(
                    'No se baja la migracion de los periodos de alta: hay readmisiones o correcciones de fecha de cese, '
                    .'y bajar perderia esa historia (regla dura 5, ADR-062 §1).',
                );
            }

            DB::statement('DROP TRIGGER IF EXISTS employees_current_period_matches ON employees');
            DB::statement('DROP TRIGGER IF EXISTS employees_guard_employment_transition ON employees');
            DB::statement('DROP TABLE IF EXISTS '.self::CORRECTIONS);
            DB::statement('DROP TABLE IF EXISTS '.self::PERIODS);
            DB::statement('DROP FUNCTION IF EXISTS public.employees_assert_current_period()');
            DB::statement('DROP FUNCTION IF EXISTS public.employees_guard_employment_transition()');
            DB::statement('DROP FUNCTION IF EXISTS public.employment_periods_guard()');
        });
    }

    private function createPeriods(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE employment_periods (
                id bigserial PRIMARY KEY,
                uuid uuid NOT NULL,
                employee_id bigint NOT NULL REFERENCES employees (id) ON DELETE RESTRICT,
                hired_on date NOT NULL,
                terminated_on date NULL,
                offboarded_at timestamptz(6) NULL,
                created_at timestamptz(6) NOT NULL DEFAULT now(),
                created_by_user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT employment_periods_uuid_unique UNIQUE (uuid),
                CONSTRAINT employment_periods_chk_terminated_after_hired
                    CHECK (terminated_on IS NULL OR terminated_on >= hired_on),
                CONSTRAINT employment_periods_chk_offboarded_has_date
                    CHECK (offboarded_at IS NULL OR terminated_on IS NOT NULL),
                CONSTRAINT employment_periods_no_overlap
                    EXCLUDE USING gist (employee_id WITH =, daterange(hired_on, terminated_on, '[]') WITH &&)
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX one_open_employment_period ON employment_periods (employee_id) WHERE offboarded_at IS NULL');
        DB::statement('CREATE INDEX employment_periods_created_by_user_id_index ON employment_periods (created_by_user_id) WHERE created_by_user_id IS NOT NULL');
    }

    private function createCorrections(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE employment_period_corrections (
                id bigserial PRIMARY KEY,
                employment_period_id bigint NOT NULL REFERENCES employment_periods (id) ON DELETE RESTRICT,
                previous_terminated_on date NOT NULL,
                terminated_on date NOT NULL,
                reason text NOT NULL,
                corrected_by_user_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                corrected_at timestamptz(6) NOT NULL,
                CONSTRAINT employment_period_corrections_chk_reason
                    CHECK (char_length(btrim(reason)) BETWEEN 10 AND 1000),
                CONSTRAINT employment_period_corrections_chk_changes
                    CHECK (terminated_on <> previous_terminated_on)
            )
        SQL);

        DB::statement('CREATE INDEX employment_period_corrections_period_index ON employment_period_corrections (employment_period_id)');
        DB::statement('CREATE INDEX employment_period_corrections_user_index ON employment_period_corrections (corrected_by_user_id)');
    }

    /**
     * Un periodo por ficha, con la baja tal como esta (ADR-062 §1).
     */
    private function backfill(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO employment_periods (uuid, employee_id, hired_on, terminated_on, offboarded_at, created_at)
            SELECT gen_random_uuid(),
                   e.id,
                   e.hired_at,
                   e.terminated_at,
                   CASE WHEN e.status = 'terminated' THEN
                       COALESCE(
                           (SELECT max(a.occurred_at)
                              FROM audit_log a
                             WHERE a.action = 'employee.offboarded'
                               AND a.payload->>'employee_uuid' = e.uuid::text),
                           e.updated_at,
                           e.created_at,
                           now())
                   END,
                   COALESCE(e.created_at, now())
              FROM employees e
             ORDER BY e.id
        SQL);
    }

    /**
     * @return list<string>
     */
    private function functions(): array
    {
        return [
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.employees_guard_employment_transition()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                BEGIN
                    IF NEW.status = OLD.status AND NEW.hired_at = OLD.hired_at
                       AND NEW.terminated_at IS NOT DISTINCT FROM OLD.terminated_at THEN
                        RETURN NEW;
                    END IF;

                    -- Suspender o levantar, sin tocar fechas (RN-25); o programar,
                    -- reprogramar o anular la baja sin cambiar de estado (RN-14).
                    IF OLD.status IN ('active', 'suspended') AND NEW.status IN ('active', 'suspended')
                       AND NEW.hired_at = OLD.hired_at
                       AND (NEW.status = OLD.status OR NEW.terminated_at IS NOT DISTINCT FROM OLD.terminated_at) THEN
                        RETURN NEW;
                    END IF;

                    -- La baja efectiva, en el acto o aplicada por la tarea.
                    IF OLD.status IN ('active', 'suspended') AND NEW.status = 'terminated'
                       AND NEW.terminated_at IS NOT NULL AND NEW.hired_at = OLD.hired_at THEN
                        RETURN NEW;
                    END IF;

                    -- La correccion de la fecha de cese (RN-27).
                    IF OLD.status = 'terminated' AND NEW.status = 'terminated'
                       AND NEW.hired_at = OLD.hired_at AND NEW.terminated_at IS NOT NULL THEN
                        RETURN NEW;
                    END IF;

                    -- La readmision (RN-27): alta nueva posterior al cese, sin cese.
                    IF OLD.status = 'terminated' AND NEW.status = 'active'
                       AND OLD.terminated_at IS NOT NULL AND NEW.terminated_at IS NULL
                       AND NEW.hired_at > OLD.terminated_at THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'employees %: transicion de % a % no admitida (ADR-062 §2)', OLD.uuid, OLD.status, NEW.status
                        USING ERRCODE = '42501';
                END;
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.employment_periods_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                BEGIN
                    IF ROW(NEW.id, NEW.uuid, NEW.employee_id, NEW.hired_on, NEW.created_at, NEW.created_by_user_id)
                       IS DISTINCT FROM ROW(OLD.id, OLD.uuid, OLD.employee_id, OLD.hired_on, OLD.created_at, OLD.created_by_user_id) THEN
                        RAISE EXCEPTION 'employment_periods %: solo cambian la fecha de cese y la baja efectiva (ADR-062 §2)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    IF OLD.offboarded_at IS NOT NULL AND NEW.offboarded_at IS DISTINCT FROM OLD.offboarded_at THEN
                        RAISE EXCEPTION 'employment_periods %: la baja efectiva no se reescribe (ADR-062 §2)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    IF OLD.offboarded_at IS NOT NULL AND NEW.terminated_on IS NULL THEN
                        RAISE EXCEPTION 'employment_periods %: un periodo con baja efectiva conserva su fecha de cese (ADR-062 §2)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NEW;
                END;
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.employees_assert_current_period()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, public, pg_temp
                AS $kronoqr$
                DECLARE
                    v_employee bigint;
                    v_status text;
                    v_hired date;
                    v_terminated date;
                    v_period record;
                BEGIN
                    IF TG_TABLE_NAME = 'employees' THEN
                        v_employee := NEW.id;
                    ELSE
                        v_employee := NEW.employee_id;
                    END IF;

                    SELECT e.status, e.hired_at, e.terminated_at INTO v_status, v_hired, v_terminated
                      FROM public.employees e WHERE e.id = v_employee;

                    SELECT p.hired_on, p.terminated_on, p.offboarded_at INTO v_period
                      FROM public.employment_periods p
                     WHERE p.employee_id = v_employee
                     ORDER BY p.hired_on DESC
                     LIMIT 1;

                    IF NOT FOUND
                       OR v_period.hired_on <> v_hired
                       OR v_period.terminated_on IS DISTINCT FROM v_terminated
                       OR (v_period.offboarded_at IS NOT NULL) <> (v_status = 'terminated') THEN
                        RAISE EXCEPTION 'employees %: la ficha no coincide con su periodo de alta en curso (ADR-062 §1)', v_employee
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $kronoqr$
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    private function triggers(): array
    {
        return [
            'CREATE TRIGGER employees_guard_employment_transition BEFORE UPDATE OF status, hired_at, terminated_at ON employees '
                .'FOR EACH ROW EXECUTE FUNCTION public.employees_guard_employment_transition()',
            'CREATE TRIGGER employment_periods_guard BEFORE UPDATE ON employment_periods '
                .'FOR EACH ROW EXECUTE FUNCTION public.employment_periods_guard()',
            'CREATE CONSTRAINT TRIGGER employees_current_period_matches AFTER INSERT OR UPDATE OF status, hired_at, terminated_at ON employees '
                .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.employees_assert_current_period()',
            'CREATE CONSTRAINT TRIGGER employment_periods_match_employee AFTER INSERT OR UPDATE ON employment_periods '
                .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.employees_assert_current_period()',
        ];
    }

    /**
     * La aplicacion inserta periodos y correcciones y solo cambia la fecha de
     * cese y la baja efectiva de un periodo (ADR-062 §2, ADR-057).
     */
    private function restrictApplication(): void
    {
        $application = '"'.AuditLogSchema::applicationRole().'"';

        foreach ([self::PERIODS, self::CORRECTIONS] as $table) {
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE '.$table.' FROM '.$application);
        }

        DB::statement('GRANT UPDATE (terminated_on, offboarded_at) ON TABLE '.self::PERIODS.' TO '.$application);

        foreach (['employees_guard_employment_transition', 'employment_periods_guard', 'employees_assert_current_period'] as $function) {
            DB::statement('REVOKE ALL ON FUNCTION public.'.$function.'() FROM PUBLIC');
        }
    }
};
