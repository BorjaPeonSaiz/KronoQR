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
 *    (rechazos, duplicados) envejecen por `LEAST(occurred_at, recorded_at)`:
 *    `occurred_at` lo pone la tablet, y uno de 2099 no se purgaria nunca (N1
 *    del dictamen del bloque 18); `recorded_at` lo pone el servidor.
 * 3b. `discarded_scan_reports` — los avisos de fichaje descartado (RN-22,
 *    ADR-047): no cuelgan de nada y envejecen igual que los escaneos sin tramo.
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
 * ## Sin `DELETE`: la purga la hace una funcion del motor
 *
 * El rol de la aplicacion no tiene `DELETE` sobre ninguna tabla del registro
 * (ADR-057 §1 y §3, migracion
 * `2026_10_14_100000_work_record_trigger_and_purge_function`). Borra
 * {@see self::PURGE_FUNCTION}, `SECURITY DEFINER` y propiedad del rol de
 * migracion, que sigue el orden de arriba y **calcula ella misma el corte mas
 * reciente admisible** con la fecha UTC del servidor de base de datos, el
 * `retention_years` del perfil y el suelo legal de su jurisdiccion. Un corte
 * posterior lo rechaza con `42501`: quien ejecute codigo con la credencial del
 * runtime no puede pedirle que borre la jornada de ayer.
 *
 * Las consultas de {@see self::inspect()} siguen aqui, con la misma forma que
 * los `DELETE` de la funcion: son las que cuentan y fechan lo que se va a
 * purgar para el informe y el asiento.
 *
 * ## Lotes, no transacciones
 *
 * La funcion vacia lo vencido de cada tabla en sentencias de `$batchSize` filas
 * como mucho, para no construir un `IN` de cien mil identificadores. Se la llama
 * una vez. La transaccion la abre el caso de uso, que es quien escribe el
 * asiento: si el asiento falla, esto no se ha borrado.
 *
 * **Sin nada que purgar no se llama.** Si {@see self::inspect()} no encuentra
 * filas vencidas, no hay nada que pedirle a la funcion, y una simulacion o un
 * reloj de la aplicacion adelantado no se convierten en un error.
 *
 * ## Que NO borra
 *
 * Ni `audit_log` -que no se borra nunca por `DELETE`, ADR-027-, ni empleados, ni
 * credenciales, ni contratos. Un trabajador con contrato en vigor no desaparece
 * porque su jornada de hace cuatro anos si.
 */
final readonly class DatabaseWorkRecordArchive implements WorkRecordArchive
{
    /**
     * La funcion `SECURITY DEFINER` que purga (ADR-057 §3). La nombran tambien
     * su migracion y las pruebas que comprueban quien la puede ejecutar.
     */
    public const string PURGE_FUNCTION = 'work_record_purge_batch';

    /**
     * Lo que dice la funcion al rechazar un corte posterior al admisible, con
     * `42501`. Distingue ese rechazo de otro `42501` (un rol sin `EXECUTE`);
     * lo ata al texto de la migracion `ApplyRetentionCommandTest`.
     */
    public const string CUTOFF_REJECTED = 'posterior al admisible';

    /** El lote mas grande que admite la funcion; uno mayor se recorta aqui. */
    public const int MAX_BATCH = 100000;

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
                   OR (se.id IS NULL AND LEAST(s.occurred_at, s.recorded_at) < ?)
            SQL, [$date, $instant]),
            // RN-22 (ADR-047, F7): los avisos de fichaje descartado envejecen
            // con `scan_events`, por LEAST(occurred_at, recorded_at). No cuelgan de nada ni
            // nada cuelga de ellos.
            $this->tally('discarded_scan_reports', <<<'SQL'
                SELECT count(*) AS row_count,
                       min(LEAST(d.occurred_at, d.recorded_at))::date::text AS oldest,
                       max(LEAST(d.occurred_at, d.recorded_at))::date::text AS newest
                FROM discarded_scan_reports d
                WHERE LEAST(d.occurred_at, d.recorded_at) < ?
            SQL, [$instant]),
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

        if (array_sum(array_map(static fn (RetentionTally $tally): int => $tally->rows, $ranges)) === 0) {
            // Nada vencido: no hay nada que pedirle a la funcion (ver el
            // docblock de la clase).
            $deleted = array_fill_keys(array_keys($ranges), 0);
        } else {
            $deleted = $this->purgeInBatches($cutoff->format('Y-m-d'), min(self::MAX_BATCH, max(1, $batchSize)));
        }

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
     * Llama una vez a {@see self::PURGE_FUNCTION}, que vacia lo vencido tabla a
     * tabla en sentencias de `$batchSize` filas como mucho, y devuelve lo
     * borrado por tabla.
     *
     * @return array<string, int>
     */
    private function purgeInBatches(string $cutoffDate, int $batchSize): array
    {
        /** @var list<object{dataset: string, deleted: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT dataset, deleted FROM public.'.self::PURGE_FUNCTION.'(?::date, ?::integer)',
            [$cutoffDate, $batchSize],
        );

        $deleted = [];

        foreach ($rows as $row) {
            $deleted[$row->dataset] = (int) $row->deleted;
        }

        return $deleted;
    }
}
