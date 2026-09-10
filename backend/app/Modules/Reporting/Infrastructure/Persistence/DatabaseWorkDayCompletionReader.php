<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * El recuento de jornadas completas de un dia, en **una sola consulta**
 * (RF-IN-08, doc 02 §8.2).
 *
 * ## Se agrupa dos veces, y la primera es la que importa
 *
 * La subconsulta reduce `shift_entries` a una fila por JORNADA —empleado y
 * fecha— con cuantos tramos tiene y cuantos estan cerrados. La de fuera cuenta
 * jornadas por centro, marcando completa la que no tiene ningun tramo abierto.
 * Sin la primera agrupacion se contarian tramos y no jornadas: alguien con
 * entrada, pausa y salida sumaria tres, y un turno partido pesaria el doble que
 * uno seguido.
 *
 * `count(*) FILTER (WHERE …)` en lugar de dos consultas: PostgreSQL lo resuelve
 * en la misma pasada, y dos consultas separadas podrian ver estados distintos.
 *
 * ## Los tramos anulados y los sustituidos quedan fuera
 *
 * `status NOT IN ('voided', 'superseded')`, el mismo predicado que usa el
 * tablero de presencia. Nada se borra en este producto (regla dura 5): una
 * correccion deja la version anterior en la tabla, y contarla haria que
 * rectificar bien un dia lo empeorase en el indicador.
 *
 * ## Va por el indice que ya existe
 *
 * `shift_entries_site_id_work_date_index`, de la migracion original. La
 * consulta filtra por `work_date` exacta y agrupa por `site_id`, que es
 * literalmente la forma del indice.
 */
final readonly class DatabaseWorkDayCompletionReader implements WorkDayCompletionReader
{
    public function __construct(private ConnectionInterface $connection) {}

    public function completionOn(string $workDate): array
    {
        $rows = $this->connection->select(<<<'SQL'
            SELECT site_id,
                   count(*)                                  AS total,
                   count(*) FILTER (WHERE open_entries = 0)  AS complete
              FROM (
                    SELECT se.site_id,
                           se.employee_id,
                           count(*) FILTER (WHERE se.clocked_out_at IS NULL) AS open_entries
                      FROM shift_entries se
                     WHERE se.work_date = ?
                       AND se.status NOT IN ('voided', 'superseded')
                     GROUP BY se.site_id, se.employee_id
                   ) AS work_days
             GROUP BY site_id
             ORDER BY site_id
            SQL, [$workDate]);

        $completion = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);

            $completion[$reader->int('site_id')] = [
                'complete' => $reader->int('complete'),
                'total' => $reader->int('total'),
            ];
        }

        return $completion;
    }
}
