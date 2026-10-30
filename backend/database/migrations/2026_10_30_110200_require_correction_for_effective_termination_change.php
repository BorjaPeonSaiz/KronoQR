<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cese de un periodo con la baja efectiva solo cambia con su correccion
 * escrita en la misma transaccion (RN-27, RN-13, RL-04; ADR-062 §7, ADR-057).
 *
 * `employment_periods_guard` deja cambiar `terminated_on` —es lo que hace la
 * correccion— y el rol de la aplicacion tiene `UPDATE (terminated_on,
 * offboarded_at)`. Sin esto, un `UPDATE` por fuera del caso de uso movia la
 * fecha de cese de una baja efectiva sin dejar la fecha anterior, el motivo ni
 * quien: justo lo que la tabla de correcciones existe para impedir.
 *
 * `employment_periods_termination_corrected`, disparador de restriccion
 * **diferido** `AFTER UPDATE OF terminated_on`: si el periodo ya tenia
 * `offboarded_at` y su `terminated_on` cambia, al confirmar exige una fila de
 * `employment_period_corrections` del mismo periodo, con esas dos fechas, que
 * haya escrito **esta transaccion**; si no, `42501`. Diferido porque el
 * repositorio escribe la correccion y el periodo en el orden que quiera.
 *
 * **«Esta transaccion»** se comprueba con el `xmin` de la fila: una fila visible
 * cuyo `xmin` sigue `in progress` solo puede ser de la propia transaccion (o de
 * una de sus subtransacciones, que es como corren los reintentos y los
 * `withChainLock` anidados). Se reconstruye el `xid8` con la epoca de la
 * transaccion en curso y solo se miran los `xmin` no anteriores a ella. Una
 * correccion de otro dia con las mismas dos fechas no vale.
 *
 * Lo que no cubre, como el resto de ADR-057: la credencial de la aplicacion
 * puede escribir ella misma la correccion. Entonces queda la fila, con su
 * autor y su motivo, y el asiento que falta lo detecta la conciliacion.
 *
 * Programar, reprogramar o anular una baja (sin `offboarded_at`) y hacerla
 * efectiva no pasan por aqui.
 *
 * ## Despliegue (expand)
 *
 * Una funcion y un disparador nuevos; ningun dato cambia. `down()` los retira.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.employment_periods_assert_termination_corrected()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                DECLARE
                    v_current bigint := pg_catalog.pg_current_xact_id()::text::bigint;
                    v_epoch   bigint := v_current >> 32;
                    v_low     bigint := v_current & 4294967295;
                BEGIN
                    IF OLD.offboarded_at IS NULL OR NEW.terminated_on IS NOT DISTINCT FROM OLD.terminated_on THEN
                        RETURN NULL;
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                          FROM public.employment_period_corrections c
                         WHERE c.employment_period_id = NEW.id
                           AND c.previous_terminated_on = OLD.terminated_on
                           AND c.terminated_on = NEW.terminated_on
                           AND c.xmin::text::bigint >= v_low
                           AND pg_catalog.pg_xact_status(((v_epoch << 32) + c.xmin::text::bigint)::text::xid8) = 'in progress'
                    ) THEN
                        RAISE EXCEPTION 'employment_periods %: la fecha de cese de una baja efectiva solo cambia con su correccion en la misma transaccion (ADR-062 §7)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NULL;
                END;
                $kronoqr$
                SQL);

            DB::statement('REVOKE ALL ON FUNCTION public.employment_periods_assert_termination_corrected() FROM PUBLIC');

            DB::statement('DROP TRIGGER IF EXISTS employment_periods_termination_corrected ON employment_periods');
            DB::statement('CREATE CONSTRAINT TRIGGER employment_periods_termination_corrected AFTER UPDATE OF terminated_on ON employment_periods'
                .' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.employment_periods_assert_termination_corrected()');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('DROP TRIGGER IF EXISTS employment_periods_termination_corrected ON employment_periods');
            DB::statement('DROP FUNCTION IF EXISTS public.employment_periods_assert_termination_corrected()');
        });
    }
};
