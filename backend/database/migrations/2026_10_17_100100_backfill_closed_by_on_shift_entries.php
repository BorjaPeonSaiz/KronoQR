<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rellena `shift_entries.closed_by` en los tramos cerrados que ya habia (RN-05,
 * ADR-058). Segunda de las tres migraciones de la fase *expand*.
 *
 * ## Que escribe
 *
 * `break_start` si el ultimo escaneo que cerro la estirpe del tramo fue una
 * pausa —la misma consulta por linaje que usaba el detalle de jornada antes de
 * tener la columna—, y `clock_out` el resto, tambien los cerrados a mano: lo
 * que el registro afirmaba de ellos. Los abiertos se quedan en nulo.
 *
 * ## Por lotes, sin transaccion global
 *
 * Escribir la salida de un tramo ya cerrado es justo lo que el *trigger* de
 * transiciones de ADR-057 §2 existe para impedir, asi que cada lote lo
 * desactiva **dentro de su propia transaccion**: `DISABLE TRIGGER`, el
 * `UPDATE` de los ids del lote y `ENABLE TRIGGER`, y se confirma. El DDL es
 * transaccional en PostgreSQL: ninguna otra sesion llega a ver la guarda
 * desactivada. Cada lote lleva los topes de `LimitsMigrationLocks` (3 s de
 * espera, 30 s de sentencia) y se avanza con un cursor sobre `id`, sin volver a
 * recorrer lo ya escrito. El despliegue soportado migra en mantenimiento, sin
 * fichajes concurrentes; la ventana medida esta en
 * `docs/runbooks/actualizacion-cliente.md` §3 bis.
 *
 * El tamaño del lote es `database.closed_by_backfill_batch` (2000 de serie):
 * configurable para que la prueba recorra mas de un lote.
 *
 * ## Vuelta atras
 *
 * `down()` no hace nada: el dato vive en la columna, que retira la migracion
 * anterior. **La vuelta atras de una actualizacion es restaurar la copia**
 * (ADR-054), no `migrate:rollback`: repetir este relleno despues de que alguien
 * haya declarado pausas a mano (`ends_in_break`) **no las reproduce**, porque
 * solo sabe leer los escaneos.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Cada lote es su propia transaccion. */
    public $withinTransaction = false;

    private const int DEFAULT_BATCH = 2000;

    public function up(): void
    {
        $batch = max(1, config()->integer('database.closed_by_backfill_batch', self::DEFAULT_BATCH));
        $lastId = 0;

        do {
            [$written, $lastId] = DB::transaction(function () use ($batch, $lastId): array {
                $this->limitLockWait();

                /** @var list<int> $ids */
                $ids = DB::table('shift_entries')
                    ->where('id', '>', $lastId)
                    ->whereNotNull('clocked_out_at')
                    ->whereNull('closed_by')
                    ->orderBy('id')
                    ->limit($batch)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                    ->all();

                if ($ids === []) {
                    return [0, $lastId];
                }

                $placeholders = implode(', ', array_fill(0, \count($ids), '?'));

                DB::statement('ALTER TABLE shift_entries DISABLE TRIGGER shift_entries_guard_transition');
                DB::update(<<<SQL
                    WITH RECURSIVE lineage(current_id, entry_id) AS (
                        SELECT se.id, se.id
                          FROM shift_entries se
                         WHERE se.id IN ({$placeholders})
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
                       SET closed_by = COALESCE(lc.closed_by, 'clock_out')
                      FROM (SELECT unnest(ARRAY[{$placeholders}]::bigint[]) AS id) batch
                      LEFT JOIN last_close lc ON lc.current_id = batch.id
                     WHERE se.id = batch.id
                    SQL, [...$ids, ...$ids]);
                DB::statement('ALTER TABLE shift_entries ENABLE TRIGGER shift_entries_guard_transition');

                return [\count($ids), $ids[\count($ids) - 1]];
            });
        } while ($written === $batch);
    }

    public function down(): void
    {
        // Nada: la columna la retira la migracion anterior. Ver la cabecera:
        // la vuelta atras es restaurar la copia (ADR-054), y repetir este
        // relleno no reproduce las pausas declaradas a mano.
    }
};
