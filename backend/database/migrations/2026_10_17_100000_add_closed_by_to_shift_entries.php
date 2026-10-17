<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `shift_entries.closed_by`: **como termino un tramo cerrado**, en salida o en
 * pausa (RN-05, RF-PA-04, ADR-024; bloque 1 de la 2.3.0). Fase *expand*.
 *
 * ## Para que
 *
 * Un tramo que empieza al dia civil siguiente solo continua la jornada si el
 * anterior termino en **pausa**, igual que en el quiosco. Hasta ahora ese dato
 * solo estaba en `scan_events` (el escaneo «Pausa»), asi que un tramo escrito a
 * mano no podia decirlo y el alta manual podia encadenar los turnos de dias
 * seguidos en una sola jornada, escondiendo un descanso insuficiente (RN-10).
 *
 * ## Que hace, en este orden
 *
 * 1. Anade la columna, nula y sin valor por defecto: solo catalogo.
 * 2. **Rellena los tramos cerrados que ya hay.** `break_start` si el ultimo
 *    escaneo que cerro su estirpe fue una pausa —la misma consulta que
 *    `DatabaseWorkDayJournalReader::clockingMarks()`—, y `clock_out` el resto,
 *    tambien los cerrados a mano. Con el *trigger* de transiciones de ADR-057 §2
 *    desactivado solo durante este `UPDATE`: escribir la salida de un tramo ya
 *    cerrado es exactamente lo que ese *trigger* existe para impedir.
 * 3. Un *trigger* `BEFORE INSERT OR UPDATE` que pone `clock_out` cuando llega una
 *    salida sin cierre. Es la compatibilidad de la fase *expand*: la version
 *    anterior, que sigue sirviendo durante el despliegue, no escribe la columna,
 *    y lo que escribe —una salida sin mas— es exactamente una salida. Tambien lo
 *    que escriben las semillas y las pruebas con SQL directo.
 * 4. La invariante en la base: `(clocked_out_at IS NULL) = (closed_by IS NULL)`
 *    y los dos valores, `NOT VALID` y validada despues, para no bloquear las
 *    escrituras mientras se recorre la tabla.
 * 5. El *trigger* de transiciones aprende la columna: solo cambia al cerrar un
 *    tramo abierto, junto con la salida. Se reescribe sobre su definicion
 *    vigente (`pg_get_functiondef`) para no copiar aqui su cuerpo entero.
 * 6. `GRANT UPDATE (closed_by)` al rol de la aplicacion (ADR-057 §1): el cierre
 *    de un tramo abierto la escribe en el `upsert`. El `INSERT` es de tabla y ya
 *    la cubre. La misma lista vive en `infra/scripts/lib/work-record-privileges.sh`.
 *
 * ## Reversible
 *
 * `down()` deshace los seis pasos en orden inverso. El dato se pierde con la
 * columna; lo que queda en `scan_events` sigue diciendo que escaneos fueron una
 * pausa, que es de donde salio.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** La validacion de la restriccion va fuera de la transaccion que la crea. */
    public $withinTransaction = false;

    private const string CHECK = 'shift_entries_chk_closed_by';

    private const string DEFAULT_TRIGGER = 'shift_entries_closed_by_default';

    private const string GUARD = 'public.shift_entries_guard_transition()';

    /**
     * Lo que se cambia en el cuerpo del *trigger* de transiciones, de -> a.
     *
     * @var array<string, string>
     */
    private const array GUARD_EDITS = [
        "'clock_out_source', 'superseded_by_id'" => "'clock_out_source', 'closed_by', 'superseded_by_id'",
        'ROW(NEW.clocked_out_at, NEW.duration_minutes, NEW.clock_out_source)' => 'ROW(NEW.clocked_out_at, NEW.duration_minutes, NEW.clock_out_source, NEW.closed_by)',
        'ROW(OLD.clocked_out_at, OLD.duration_minutes, OLD.clock_out_source)' => 'ROW(OLD.clocked_out_at, OLD.duration_minutes, OLD.clock_out_source, OLD.closed_by)',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();
            $this->expand();
        });

        $this->validateConstraint('shift_entries', self::CHECK);
    }

    private function expand(): void
    {
        DB::statement('ALTER TABLE shift_entries ADD COLUMN closed_by varchar(16) NULL');

        DB::statement('ALTER TABLE shift_entries DISABLE TRIGGER shift_entries_guard_transition');
        DB::statement("UPDATE shift_entries SET closed_by = 'clock_out' WHERE clocked_out_at IS NOT NULL");
        DB::statement(<<<'SQL'
            WITH RECURSIVE lineage(current_id, entry_id) AS (
                SELECT se.id, se.id
                  FROM shift_entries se
                 WHERE se.clocked_out_at IS NOT NULL
                 UNION ALL
                SELECT l.current_id, previous.id
                  FROM shift_entries previous
                  JOIN lineage l ON previous.superseded_by_id = l.entry_id
                 WHERE previous.superseded_by_id IS NOT NULL
            ), last_close AS (
                SELECT l.current_id,
                       (ARRAY_AGG(ev.result ORDER BY ev.occurred_at DESC)
                          FILTER (WHERE ev.result IN ('clock_out', 'break_start')))[1] AS closed_by
                  FROM lineage l
                  JOIN scan_events ev ON ev.shift_entry_id = l.entry_id
                 GROUP BY l.current_id
            )
            UPDATE shift_entries se
               SET closed_by = 'break_start'
              FROM last_close lc
             WHERE se.id = lc.current_id
               AND lc.closed_by = 'break_start'
            SQL);
        DB::statement('ALTER TABLE shift_entries ENABLE TRIGGER shift_entries_guard_transition');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.shift_entries_closed_by_default()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            BEGIN
                IF NEW.clocked_out_at IS NOT NULL AND NEW.closed_by IS NULL THEN
                    NEW.closed_by := 'clock_out';
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL);
        DB::statement('CREATE TRIGGER '.self::DEFAULT_TRIGGER.' BEFORE INSERT OR UPDATE ON shift_entries '
            .'FOR EACH ROW EXECUTE FUNCTION public.shift_entries_closed_by_default()');

        DB::statement('ALTER TABLE shift_entries ADD CONSTRAINT '.self::CHECK.' CHECK ('
            ."(clocked_out_at IS NULL) = (closed_by IS NULL) AND (closed_by IS NULL OR closed_by IN ('clock_out', 'break_start'))"
            .') NOT VALID');

        $this->rewriteGuard(self::GUARD_EDITS);

        DB::statement('GRANT UPDATE (closed_by) ON TABLE shift_entries TO '.$this->applicationRole());
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('REVOKE UPDATE (closed_by) ON TABLE shift_entries FROM '.$this->applicationRole());
            $this->rewriteGuard(array_flip(self::GUARD_EDITS));
            DB::statement('ALTER TABLE shift_entries DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('DROP TRIGGER IF EXISTS '.self::DEFAULT_TRIGGER.' ON shift_entries');
            DB::statement('DROP FUNCTION IF EXISTS public.shift_entries_closed_by_default()');
            DB::statement('ALTER TABLE shift_entries DROP COLUMN closed_by');
        });
    }

    /**
     * Reescribe el *trigger* de transiciones sobre su definicion vigente.
     *
     * Falla si alguna sustitucion no encuentra su texto: un cuerpo que no es el
     * esperado no se toca a ciegas.
     *
     * @param  array<string, string>  $edits
     */
    private function rewriteGuard(array $edits): void
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(?::regprocedure)', [self::GUARD]);

        if (! is_string($definition)) {
            throw new RuntimeException('No existe '.self::GUARD.': falta la migracion 2026_10_14_100000.');
        }

        foreach ($edits as $from => $to) {
            if (! str_contains($definition, $from)) {
                throw new RuntimeException(self::GUARD.' no tiene el texto esperado: '.$from);
            }

            $definition = str_replace($from, $to, $definition);
        }

        // @phpstan-ignore argument.type (la definicion sale de pg_get_functiondef sobre una funcion del propio esquema y solo cambia por las sustituciones literales de GUARD_EDITS; no hay entrada externa)
        DB::unprepared($definition);
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
