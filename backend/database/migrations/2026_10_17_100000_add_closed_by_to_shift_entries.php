<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `shift_entries.closed_by`: **como termino un tramo cerrado**, en salida o en
 * pausa (RN-05, RF-PA-04, ADR-058). Primera de tres migraciones de la fase
 * *expand*; esta solo toca el catalogo.
 *
 * ## Para que
 *
 * Un tramo que empieza al dia civil siguiente solo continua la jornada si el
 * anterior termino en **pausa**, igual que en el quiosco. Hasta ahora ese dato
 * solo estaba en `scan_events` (el escaneo «Pausa»), asi que un tramo escrito a
 * mano no podia decirlo y el alta manual podia encadenar los turnos de dias
 * seguidos en una sola jornada, escondiendo un descanso insuficiente (RN-10).
 *
 * ## Las tres migraciones
 *
 * 1. **Esta**, en una transaccion y sin leer filas: la columna nula, el
 *    *trigger* por defecto, la guarda de transiciones y el `GRANT`.
 * 2. `2026_10_17_100100`: el relleno de los tramos que ya hay, por lotes.
 * 3. `2026_10_17_100200`: la restriccion `CHECK`, creada `NOT VALID` y validada
 *    despues.
 *
 * ## Que hace esta
 *
 * - La columna, nula y sin valor por defecto: solo catalogo.
 * - Un *trigger* `BEFORE INSERT OR UPDATE` que pone `clock_out` cuando un tramo
 *   nace cerrado o se cierra sin cierre declarado (ADR-058 §4). Existe para lo
 *   que escribe sin pasar por el dominio: las semillas, las pruebas y el SQL
 *   directo; lo que escriben —una salida sin mas— es exactamente una salida. No
 *   toca un tramo que ya estaba cerrado. Se retira en la fase *contract*.
 *
 * **El despliegue soportado migra en mantenimiento**, sin fichajes
 * concurrentes (`update.sh`). Migrar con la version anterior atendiendo **no
 * esta soportado**: las pausas que cerrara quedarian como `clock_out`, y
 * corregir un tramo que el relleno aun no ha alcanzado daria `42501`.
 * - El *trigger* de transiciones de ADR-057 §2 aprende la columna: solo cambia
 *   al cerrar un tramo abierto, junto con la salida. Se reescribe sobre su
 *   definicion vigente (`pg_get_functiondef`) para no copiar aqui su cuerpo.
 * - `GRANT UPDATE (closed_by)` al rol de la aplicacion (ADR-057 §1): el cierre
 *   de un tramo abierto la escribe en el `upsert`. El `INSERT` es de tabla y ya
 *   la cubre. La misma lista vive en `infra/scripts/lib/work-record-privileges.sh`.
 *
 * ## Bloqueos
 *
 * `ALTER TABLE … ADD COLUMN` sin valor por defecto y `CREATE TRIGGER` toman un
 * bloqueo exclusivo **breve** sobre `shift_entries`: solo catalogo, sin
 * recorrer la tabla. Con `lock_timeout` corto, si una transaccion larga la
 * tuviera tomada la migracion se rinde en vez de encolar el fichaje detras.
 *
 * ## Reversible
 *
 * `down()` deshace lo de esta migracion en orden inverso; con la columna se va
 * el dato, y lo que queda en `scan_events` sigue diciendo que escaneos fueron
 * una pausa, que es de donde salio.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

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
        $this->limitLockWait();

        DB::statement('ALTER TABLE shift_entries ADD COLUMN closed_by varchar(16) NULL');

        // `OR REPLACE`: `migrate:fresh` borra las tablas pero no las funciones.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.shift_entries_closed_by_default()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
            BEGIN
                IF NEW.clocked_out_at IS NULL OR NEW.closed_by IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                -- Solo al nacer el tramo o al cerrarlo: un tramo ya cerrado no
                -- se rellena solo (eso lo hace el relleno por lotes, y la
                -- guarda de transiciones no lo dejaria).
                IF TG_OP = 'INSERT' THEN
                    NEW.closed_by := 'clock_out';
                ELSIF OLD.clocked_out_at IS NULL THEN
                    NEW.closed_by := 'clock_out';
                END IF;

                RETURN NEW;
            END;
            $kronoqr$
            SQL);
        // Su nombre ordena antes que `shift_entries_guard_transition`: los
        // `BEFORE` corren por orden alfabetico, y la guarda tiene que ver la
        // columna ya rellena.
        DB::statement('CREATE TRIGGER '.self::DEFAULT_TRIGGER.' BEFORE INSERT OR UPDATE ON shift_entries '
            .'FOR EACH ROW EXECUTE FUNCTION public.shift_entries_closed_by_default()');

        $this->rewriteGuard(self::GUARD_EDITS);

        DB::statement('GRANT UPDATE (closed_by) ON TABLE shift_entries TO '.$this->applicationRole());
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('REVOKE UPDATE (closed_by) ON TABLE shift_entries FROM '.$this->applicationRole());
        $this->rewriteGuard(array_flip(self::GUARD_EDITS));
        DB::statement('DROP TRIGGER IF EXISTS '.self::DEFAULT_TRIGGER.' ON shift_entries');
        DB::statement('DROP FUNCTION IF EXISTS public.shift_entries_closed_by_default()');
        DB::statement('ALTER TABLE shift_entries DROP COLUMN closed_by');
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
