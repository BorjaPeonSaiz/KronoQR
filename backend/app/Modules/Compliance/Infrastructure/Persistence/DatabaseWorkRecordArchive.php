<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\WorkRecordArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * El registro de jornada vencido, contado y borrado por lotes (RL-02).
 *
 * ## El orden de borrado no es una preferencia
 *
 * Todas las claves ajenas del esquema son `ON DELETE RESTRICT` (doc 01 §5.5): no
 * hay cascada, y borrar un tramo con correcciones colgando **falla**. El orden
 * es, por tanto, de hijo a padre:
 *
 * 1. `shift_corrections` — cuelgan del tramo (RN-13).
 * 2. `incidents` — cuelgan del tramo y de la jornada.
 * 3. `scan_events` — cuelgan del tramo; los que no llegaron a producir ninguno
 *    (rechazos, duplicados) envejecen por su `occurred_at`.
 * 4. `shift_entries` — el registro legal en si.
 * 5. `daily_totals` — la proyeccion, que no tiene padre.
 *
 * ## El autorreferente de `shift_entries`
 *
 * `superseded_by_id` apunta de la version **antigua** a la **nueva** (RN-13), y
 * `RESTRICT` se comprueba fila a fila: un `DELETE` que se llevara por delante a
 * las dos a la vez podria fallar segun el orden en que el motor las visite. Se
 * borra por generaciones -solo las filas a las que ya no apunta nadie- y se
 * repite hasta que no queda ninguna. El efecto util es doble: ademas de no
 * chocar con la restriccion, **una version vencida cuya version nueva sigue
 * vigente no se borra**, que es exactamente lo que hay que conservar.
 *
 * ## Lotes, no transacciones
 *
 * `$batchSize` acota cuantas filas toca cada sentencia para no construir un
 * `IN` de cien mil identificadores. La transaccion la abre el caso de uso, que
 * es quien escribe el asiento: si el asiento falla, esto no se ha borrado.
 *
 * ## Que NO borra
 *
 * Ni `audit_log` -que no se borra nunca por `DELETE`, ADR-027-, ni empleados, ni
 * credenciales, ni contratos. Un trabajador con contrato en vigor no desaparece
 * porque su jornada de hace cuatro anos si.
 */
final readonly class DatabaseWorkRecordArchive implements WorkRecordArchive
{
    public function __construct(private ConnectionInterface $connection) {}

    public function inspect(DateTimeImmutable $cutoff): array
    {
        $date = $cutoff->format('Y-m-d');
        $instant = $cutoff->format(DateTimeImmutable::ATOM);

        return [
            $this->tally('shift_corrections', <<<'SQL'
                SELECT count(*) AS row_count,
                       min(sc.created_at)::date::text AS oldest,
                       max(sc.created_at)::date::text AS newest
                FROM shift_corrections sc
                JOIN shift_entries se ON se.id = sc.shift_entry_id
                WHERE se.work_date < ?
            SQL, [$date]),
            $this->tally('incidents', <<<'SQL'
                SELECT count(*) AS row_count,
                       min(i.work_date)::text AS oldest,
                       max(i.work_date)::text AS newest
                FROM incidents i
                LEFT JOIN shift_entries se ON se.id = i.shift_entry_id
                WHERE i.work_date < ? OR se.work_date < ?
            SQL, [$date, $date]),
            $this->tally('scan_events', <<<'SQL'
                SELECT count(*) AS row_count,
                       min(s.occurred_at)::date::text AS oldest,
                       max(s.occurred_at)::date::text AS newest
                FROM scan_events s
                LEFT JOIN shift_entries se ON se.id = s.shift_entry_id
                WHERE (se.id IS NOT NULL AND se.work_date < ?)
                   OR (se.id IS NULL AND s.occurred_at < ?)
            SQL, [$date, $instant]),
            $this->tally('shift_entries', <<<'SQL'
                SELECT count(*) AS row_count,
                       min(work_date)::text AS oldest,
                       max(work_date)::text AS newest
                FROM shift_entries
                WHERE work_date < ?
            SQL, [$date]),
            $this->tally('daily_totals', <<<'SQL'
                SELECT count(*) AS row_count,
                       min(work_date)::text AS oldest,
                       max(work_date)::text AS newest
                FROM daily_totals
                WHERE work_date < ?
            SQL, [$date]),
        ];
    }

    public function purge(DateTimeImmutable $cutoff, int $batchSize): array
    {
        // Los rangos se leen ANTES de borrar: despues ya no hay de donde. Los
        // recuentos, en cambio, son los que de verdad se han borrado.
        $ranges = [];

        foreach ($this->inspect($cutoff) as $tally) {
            $ranges[$tally->dataset] = $tally;
        }

        $date = $cutoff->format('Y-m-d');
        $instant = $cutoff->format(DateTimeImmutable::ATOM);
        $limit = max(1, $batchSize);

        $deleted = [
            'shift_corrections' => $this->deleteInBatches(<<<'SQL'
                DELETE FROM shift_corrections WHERE id IN (
                    SELECT sc.id
                    FROM shift_corrections sc
                    JOIN shift_entries se ON se.id = sc.shift_entry_id
                    WHERE se.work_date < ?
                    LIMIT ?
                )
            SQL, [$date, $limit]),
            'incidents' => $this->deleteInBatches(<<<'SQL'
                DELETE FROM incidents WHERE id IN (
                    SELECT i.id
                    FROM incidents i
                    LEFT JOIN shift_entries se ON se.id = i.shift_entry_id
                    WHERE i.work_date < ? OR se.work_date < ?
                    LIMIT ?
                )
            SQL, [$date, $date, $limit]),
            'scan_events' => $this->deleteInBatches(<<<'SQL'
                DELETE FROM scan_events WHERE id IN (
                    SELECT s.id
                    FROM scan_events s
                    LEFT JOIN shift_entries se ON se.id = s.shift_entry_id
                    WHERE (se.id IS NOT NULL AND se.work_date < ?)
                       OR (se.id IS NULL AND s.occurred_at < ?)
                    LIMIT ?
                )
            SQL, [$date, $instant, $limit]),
            // Por generaciones: solo las versiones a las que ya no apunta nadie.
            'shift_entries' => $this->deleteInBatches(<<<'SQL'
                DELETE FROM shift_entries WHERE id IN (
                    SELECT se.id
                    FROM shift_entries se
                    WHERE se.work_date < ?
                      AND NOT EXISTS (
                          SELECT 1 FROM shift_entries newer WHERE newer.superseded_by_id = se.id
                      )
                    LIMIT ?
                )
            SQL, [$date, $limit]),
            'daily_totals' => $this->deleteInBatches(<<<'SQL'
                DELETE FROM daily_totals WHERE id IN (
                    SELECT id FROM daily_totals WHERE work_date < ? LIMIT ?
                )
            SQL, [$date, $limit]),
        ];

        $tallies = [];

        foreach ($deleted as $table => $rows) {
            $range = $ranges[$table] ?? null;

            $tallies[] = new RetentionTally(
                scope: RetentionScope::WorkRecords,
                dataset: $table,
                rows: $rows,
                oldest: $range?->oldest,
                newest: $range?->newest,
            );
        }

        return $tallies;
    }

    /**
     * @param  list<string|int>  $bindings
     */
    private function tally(string $table, string $sql, array $bindings): RetentionTally
    {
        /** @var object{row_count: int|string, oldest: string|null, newest: string|null}|null $row */
        $row = $this->connection->selectOne($sql, $bindings);

        return new RetentionTally(
            scope: RetentionScope::WorkRecords,
            dataset: $table,
            rows: (int) ($row->row_count ?? 0),
            oldest: $row->oldest ?? null,
            newest: $row->newest ?? null,
        );
    }

    /**
     * Repite la sentencia hasta que no borra nada mas.
     *
     * El `LIMIT` va dentro de la subconsulta, asi que cada vuelta se lleva como
     * mucho `$batchSize` filas. Termina siempre: o borra -y hay menos-, o no
     * borra -y se sale-.
     *
     * @param  list<string|int>  $bindings
     */
    private function deleteInBatches(string $sql, array $bindings): int
    {
        $total = 0;

        do {
            $affected = $this->connection->affectingStatement($sql, $bindings);
            $total += $affected;
        } while ($affected > 0);

        return $total;
    }
}
