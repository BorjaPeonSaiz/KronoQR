<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La duracion que se escribe en un tramo es **la de sus marcas**, tambien en el
 * motor (RN-06, RN-09, regla dura 7, ADR-007, ADR-057 §2; tarea 19.6 de la
 * 2.3.0, «se paso por alto» de la tanda 3 de la 2.2.1).
 *
 * ## Lo que cierra
 *
 * `shift_entries.duration_minutes` se escribe una vez, al cerrar el tramo, con
 * `ShiftEntry::workedDuration()` (la regla unica de `ElapsedMinutes`: segundos
 * enteros de cada marca, restados y divididos entre sesenta truncando), y el
 * *trigger* `shift_entries_guard_transition` lo congela desde entonces. Pero
 * nada impedia **escribirlo mal la primera vez** por un camino que no fuera el
 * agregado: un `INSERT` o un cierre con la credencial del runtime y una
 * duracion que no cuadra con las marcas. `daily_totals` se recalcula desde las
 * marcas (ADR-007) y la exportacion legal suma la columna guardada, asi que ese
 * tramo hacia que el registro se contradijera a si mismo.
 *
 * ## `shift_entries_guard_duration()`
 *
 * Rechaza con `23514` (`check_violation`) la fila cuya duracion no es
 * `div(floor(epoch(salida)) - floor(epoch(entrada)), 60)`, o que tiene duracion
 * sin salida o salida sin duracion. `EXTRACT(EPOCH …)` devuelve `numeric` desde
 * PostgreSQL 14: el `floor` es exacto, sin el redondeo de un `double` con seis
 * decimales. Es la misma cuenta que `ElapsedMinutes::between()`; la prueba
 * `ShiftEntryDurationGuardTest` las ata con las marcas que separan las dos
 * formas de redondear.
 *
 * Dos *triggers* `AFTER` con la misma funcion, por la misma razon que
 * `shift_entries_guard_insert`: el `upsert` de `EloquentWorkDayRepository`
 * dispara los `BEFORE INSERT` tambien en el camino de `ON CONFLICT DO UPDATE`.
 *
 * - `shift_entries_guard_duration_insert`, `AFTER INSERT`: todo tramo que nace.
 * - `shift_entries_guard_duration_close`, `AFTER UPDATE … WHEN (OLD.clocked_out_at
 *   IS NULL)`: el cierre, que es la unica vez que la duracion cambia.
 *
 * ## Por que un *trigger* y no un `CHECK`
 *
 * **Las filas antiguas no se reescriben** (regla dura 5) y una que no cuadrara
 * —sembrada, o tocada por fuera— es justo la evidencia que la conciliacion de
 * ADR-057 §4 tiene que encontrar. Un `CHECK` validado haria fallar la
 * actualizacion de una instalacion con una sola de esas filas. Uno `NOT VALID`
 * rige en cada `UPDATE`, y el `upsert` reescribe los tramos cerrados de la
 * jornada en cada fichaje: un tramo antiguo descuadrado bloquearia los fichajes
 * de ese dia, que es lo que el `CASE` del repositorio existe para evitar. El
 * *trigger* solo mira las filas que se escriben de verdad por primera vez.
 *
 * ## Despliegue y bloqueos
 *
 * Un solo paso, sin expand/contract: una funcion y dos *triggers*; no lee ni
 * reescribe filas. `CREATE TRIGGER` toma `SHARE ROW EXCLUSIVE` mientras escribe
 * el catalogo y `lock_timeout` lo hace rendirse en 3 s. El codigo de antes y el
 * de ahora escriben la misma duracion, asi que convivir no rompe nada.
 *
 * ## Reversible
 *
 * `down()` suelta los dos *triggers* y la funcion. No toca filas.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.shift_entries_guard_duration()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            BEGIN
                IF (NEW.clocked_out_at IS NULL) IS DISTINCT FROM (NEW.duration_minutes IS NULL)
                   OR NEW.duration_minutes IS DISTINCT FROM pg_catalog.div(
                       pg_catalog.floor(EXTRACT(EPOCH FROM NEW.clocked_out_at))
                           - pg_catalog.floor(EXTRACT(EPOCH FROM NEW.clocked_in_at)),
                       60
                   ) THEN
                    RAISE EXCEPTION 'shift_entries %: la duracion de un tramo es la de sus marcas (RN-06, RN-09)', NEW.uuid
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $kronoqr$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.shift_entries_guard_duration() FROM PUBLIC');

        DB::statement('DROP TRIGGER IF EXISTS shift_entries_guard_duration_insert ON shift_entries');
        DB::statement('CREATE TRIGGER shift_entries_guard_duration_insert AFTER INSERT ON shift_entries '
            .'FOR EACH ROW EXECUTE FUNCTION public.shift_entries_guard_duration()');

        DB::statement('DROP TRIGGER IF EXISTS shift_entries_guard_duration_close ON shift_entries');
        DB::statement('CREATE TRIGGER shift_entries_guard_duration_close AFTER UPDATE ON shift_entries '
            .'FOR EACH ROW WHEN (OLD.clocked_out_at IS NULL) '
            .'EXECUTE FUNCTION public.shift_entries_guard_duration()');
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('DROP TRIGGER IF EXISTS shift_entries_guard_duration_close ON shift_entries');
        DB::statement('DROP TRIGGER IF EXISTS shift_entries_guard_duration_insert ON shift_entries');
        DB::statement('DROP FUNCTION IF EXISTS public.shift_entries_guard_duration()');
    }
};
