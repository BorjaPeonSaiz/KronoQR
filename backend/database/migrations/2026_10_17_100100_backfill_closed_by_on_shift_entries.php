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
 * desactivada, y el bloqueo de tabla que toma `DISABLE TRIGGER` (`SHARE ROW
 * EXCLUSIVE`, que para las escrituras) dura lo que un lote, no lo que la tabla.
 * Cada lote lleva los topes de `LimitsMigrationLocks` (3 s de espera, 30 s de
 * sentencia). La ventana medida esta en `docs/runbooks/actualizacion-cliente.md`.
 *
 * Es idempotente: cada lote coge filas cerradas con `closed_by` nulo, asi que
 * repetirla tras un fallo sigue donde se quedo. Lo que cierra la version nueva
 * entre una migracion y otra ya trae su cierre.
 *
 * ## Reversible
 *
 * `down()` no hace nada: el dato vive en la columna, que retira la migracion
 * anterior, y la siguiente vez que se aplique esta se vuelve a calcular igual.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Cada lote es su propia transaccion. */
    public $withinTransaction = false;

    /** Filas por lote: unos cientos de milisegundos con el linaje de cada una. */
    private const int BATCH = 2000;

    public function up(): void
    {
        do {
            $written = DB::transaction(function (): int {
                $this->limitLockWait();

                /** @var list<int> $ids */
                $ids = DB::table('shift_entries')
                    ->whereNotNull('clocked_out_at')
                    ->whereNull('closed_by')
                    ->orderBy('id')
                    ->limit(self::BATCH)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                    ->all();

                if ($ids === []) {
                    return 0;
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

                return \count($ids);
            });
        } while ($written === self::BATCH);
    }

    public function down(): void
    {
        // Nada: ver la cabecera.
    }
};
