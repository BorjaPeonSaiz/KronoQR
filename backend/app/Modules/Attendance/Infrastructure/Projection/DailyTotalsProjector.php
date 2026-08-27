<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Projection;

use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Mantiene `daily_totals`, que es una **proyeccion de lectura reconstruible** y
 * no una fuente de verdad (RN-06, ADR-007, regla dura 7).
 *
 * ## Recalcula, nunca incrementa
 *
 * El evento `DailyTotalsRecalculated` transporta el **estado completo** de la
 * jornada —total, numero de tramos, primera entrada, ultima salida—, no un
 * delta. Aqui se escribe lo que llega, tal cual.
 *
 * Un acumulador —`total_minutes = total_minutes + 240`— seria correcto hasta la
 * primera correccion. A partir de ahi el total no podria **bajar**, que es
 * justo lo que tiene que hacer cuando se anula un tramo (RN-13, tarea 1.15), y
 * el error se descubriria en la nomina de alguien. Con el recalculo, anular un
 * tramo cambia el resultado sin que nadie tenga que acordarse de restar, y la
 * comprobacion de integridad es trivial y automatizable —la reconciliacion
 * nocturna de RF-PR-02 y el comando `attendance:reconcile` de la tarea 2.7—:
 *
 * ```sql
 * SELECT employee_id, work_date, total_minutes FROM daily_totals;
 * SELECT employee_id, work_date, SUM(duration_minutes) FROM shift_entries
 *  WHERE status NOT IN ('voided','superseded') GROUP BY 1,2;
 * ```
 *
 * Las dos consultas tienen que devolver lo mismo, siempre, porque el total del
 * evento es la suma de los tramos vigentes del agregado y el agregado carga
 * exactamente ese conjunto (ADR-026).
 *
 * ## Es un listener, y eso lo decide la arquitectura, no la comodidad
 *
 * `Infrastructure/Persistence` **no puede** depender de `Infrastructure/
 * Projection` —Deptrac lo verifica, doc 02 §1.6— asi que el repositorio no
 * puede invocarlo. Y no debe: el §2 del doc 02 describe este directorio
 * literalmente como *«listeners que mantienen `daily_totals`»*. El agregado
 * emite el hecho y la proyeccion reacciona, que es la misma relacion que hay
 * entre `Attendance` y `Compliance`.
 *
 * ## Dentro de la transaccion del fichaje
 *
 * `RegisterScanHandler` publica sus eventos **antes de confirmar** y el
 * despachador de Laravel es sincrono, asi que este `UPSERT` entra en la misma
 * transaccion que escribio el tramo: **no puede quedar divergente** (regla dura
 * 7). Si el tramo revierte, el total revierte con el. Si esta proyeccion
 * fallara, el fichaje no se confirmaria — que es preferible a confirmarlo con un
 * total que miente.
 *
 * `ON CONFLICT (employee_id, work_date) DO UPDATE` es lo que hace que dos
 * transacciones concurrentes del mismo empleado no creen dos filas del mismo
 * dia.
 */
final readonly class DailyTotalsProjector
{
    public function __construct(private ConnectionInterface $connection) {}

    public function handle(DailyTotalsRecalculated $event): void
    {
        // `employees.id` se resuelve en la propia sentencia y no con una
        // consulta previa: es una clave foranea que el esquema ya declara, y
        // resolverla aqui evita una ida y vuelta mas por fichaje. Si el empleado
        // no existiera, la subconsulta daria NULL y la restriccion NOT NULL
        // rechazaria la fila — que es lo correcto: una proyeccion huerfana es
        // peor que una escritura fallida.
        $this->connection->statement(<<<'SQL'
            INSERT INTO daily_totals (
                employee_id, work_date, total_minutes, shift_count,
                first_in_at, last_out_at, has_open_shift, has_incident, recalculated_at
            )
            VALUES (
                (SELECT id FROM employees WHERE uuid = ?),
                ?::date, ?, ?, ?::timestamptz, ?::timestamptz, ?, ?, ?::timestamptz
            )
            ON CONFLICT (employee_id, work_date) DO UPDATE
               SET total_minutes   = EXCLUDED.total_minutes,
                   shift_count     = EXCLUDED.shift_count,
                   first_in_at     = EXCLUDED.first_in_at,
                   last_out_at     = EXCLUDED.last_out_at,
                   has_open_shift  = EXCLUDED.has_open_shift,
                   has_incident    = EXCLUDED.has_incident,
                   recalculated_at = EXCLUDED.recalculated_at
        SQL, [
            $event->employeeUuid,
            $event->workDate->isoDate,
            $event->total->minutes,
            $event->shiftCount,
            $this->timestamp($event->firstClockInAt),
            // Nulo mientras la jornada tenga un tramo abierto: una «ultima
            // salida» inventada convertiria un turno en curso en uno terminado.
            $this->timestamp($event->lastClockOutAt),
            $event->hasOpenShift,
            $event->hasAnomaly,
            // El instante del fichaje que provoco el recalculo, que es lo que
            // transporta el evento. La reconciliacion de RF-PR-02 compara la
            // proyeccion con los eventos origen, y esta marca es la que dice de
            // cuando es lo que esta comparando.
            $this->timestamp($event->recalculatedAt),
        ]);
    }

    /**
     * Precision de microsegundos y desplazamiento explicito. Sin los seis
     * decimales, la hora leida no seria la escrita, y en un registro con valor
     * legal eso no es aceptable.
     */
    private function timestamp(?DateTimeImmutable $instant): ?string
    {
        return $instant?->format('Y-m-d H:i:s.uP');
    }
}
