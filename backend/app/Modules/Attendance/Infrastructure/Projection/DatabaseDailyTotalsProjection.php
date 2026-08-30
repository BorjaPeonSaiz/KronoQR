<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Projection;

use App\Modules\Attendance\Application\Port\DailyTotalsProjection;
use App\Modules\Attendance\Application\Port\ProjectedDailyTotal;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * Lee `daily_totals` tal como esta escrita, para que la reconciliacion pueda
 * contrastarla con sus eventos origen (RF-PR-02, ADR-007, tarea 2.7).
 *
 * **Vive junto al proyector y no en `Persistence`** a proposito: es la otra cara
 * de la misma tabla derivada. `Infrastructure/Persistence` guarda la fuente de
 * verdad —`shift_entries`— y esta carpeta es, en palabras del doc 02 §2, la de
 * «los listeners que mantienen `daily_totals`». Quien venga a cambiar la forma
 * de la proyeccion encuentra aqui sus dos mitades: la que escribe y la que
 * comprueba.
 *
 * **No escribe.** La escritura tiene un solo camino, {@see DailyTotalsProjector},
 * y añadir aqui un `update()` daria a la reconciliacion una aritmetica propia con
 * la que «arreglar» filas: el dia que las dos formulas divergieran, la
 * comprobacion estaria hecha por el mismo codigo que se comprueba.
 *
 * **Se une a `employees` solo por el UUID publico**, que es la unica columna de
 * otro modulo que este modulo lee, y solo porque `daily_totals.employee_id` es
 * una clave foranea a esa tabla. Es el mismo criterio del repositorio y del
 * ledger.
 *
 * Los valores se leen con {@see Row} y no con moldes sueltos: el driver de
 * PostgreSQL no promete si un `integer` llega como `int` o como `string`, y un
 * `(int)` sobre una columna nula produce un cero que parece un dato — que en una
 * comparacion de integridad seria una divergencia inventada.
 */
final readonly class DatabaseDailyTotalsProjection implements DailyTotalsProjection
{
    public function __construct(private ConnectionInterface $connection) {}

    public function between(WorkDate $from, WorkDate $to): array
    {
        $rows = $this->connection->select(<<<'SQL'
            SELECT e.uuid AS employee_uuid,
                   d.work_date::text AS work_date,
                   d.total_minutes,
                   d.shift_count,
                   d.first_in_at,
                   d.last_out_at,
                   d.has_open_shift,
                   d.has_incident,
                   d.recalculated_at
              FROM daily_totals d
              JOIN employees e ON e.id = d.employee_id
             WHERE d.work_date BETWEEN ?::date AND ?::date
             ORDER BY d.work_date, e.uuid
        SQL, [$from->isoDate, $to->isoDate]);

        // `array_values` porque el puerto promete una lista y `select()` devuelve
        // un array cuyas claves PHPStan no puede dar por consecutivas.
        return array_values(array_map(
            static function (object $raw): ProjectedDailyTotal {
                $row = Row::of($raw);

                return new ProjectedDailyTotal(
                    employeeUuid: $row->string('employee_uuid'),
                    workDate: $row->string('work_date'),
                    totalMinutes: $row->int('total_minutes'),
                    shiftCount: $row->int('shift_count'),
                    firstClockInAt: $row->nullableInstant('first_in_at'),
                    lastClockOutAt: $row->nullableInstant('last_out_at'),
                    hasOpenShift: $row->bool('has_open_shift'),
                    hasIncident: $row->bool('has_incident'),
                    recalculatedAt: $row->instant('recalculated_at'),
                );
            },
            $rows,
        ));
    }
}
