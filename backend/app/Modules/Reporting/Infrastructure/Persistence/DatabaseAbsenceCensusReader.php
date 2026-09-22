<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\AbsenceCensusReader;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see AbsenceCensusReader} sobre PostgreSQL: quien esta ausente **hoy**, por
 * tipo (RF-GP-04, doc 02 §8.2).
 *
 * ## Una consulta, sin `AT TIME ZONE` y sin `CURRENT_DATE`
 *
 * La fecha civil llega ya resuelta desde el caso de uso, que la saca del puerto
 * `Clock` y de la zona del centro (regla dura 2 y ADR-040). `CURRENT_DATE` seria
 * la fecha del servidor de base de datos en su zona, que es UTC: a las 00:30 de
 * Madrid en invierno diria «ayer», y la metrica de las noches de fin de mes
 * contaria un dia distinto del que enseña el panel.
 *
 * ## Va por el indice parcial del informe
 *
 * `(starts_on, ends_on) WHERE status = 'active'`, el mismo que usa el informe
 * por periodo. `status = 'active'` es lo que hace que el indice parcial sirva, y
 * ademas es la unica lectura correcta: una ausencia corregida deja la version
 * anterior en `superseded` y una anulada en `voided`, y ninguna de las dos
 * describe a nadie ausente hoy (regla dura 5).
 *
 * ## `count(DISTINCT employee_id)` y no `count(*)`
 *
 * La restriccion de exclusion de `absences` ya impide dos ausencias activas
 * solapadas de la misma persona, asi que hoy las dos cifras coinciden. Se
 * escribe igualmente asi porque lo que la serie afirma es **personas**, y un
 * indicador que cuente filas se convierte en otro el dia que el esquema cambie.
 */
final readonly class DatabaseAbsenceCensusReader implements AbsenceCensusReader
{
    public function __construct(private ConnectionInterface $connection) {}

    public function activeOn(string $isoDate): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT a.type                        AS type,
                   count(DISTINCT a.employee_id) AS people
              FROM absences a
              JOIN employees e ON e.id = a.employee_id
             WHERE a.status = 'active'
               AND ?::date BETWEEN a.starts_on AND a.ends_on
               -- De alta ese dia, igual que en el informe por periodo: una
               -- ausencia de quien ya causo baja no describe a nadie ausente hoy.
               AND e.hired_at::date <= ?::date
               AND (e.terminated_at IS NULL OR e.terminated_at::date >= ?::date)
             GROUP BY a.type
             ORDER BY a.type
            SQL, [$isoDate, $isoDate, $isoDate]);

        $counts = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);
            $counts[$reader->string('type')] = $reader->int('people');
        }

        return $counts;
    }
}
