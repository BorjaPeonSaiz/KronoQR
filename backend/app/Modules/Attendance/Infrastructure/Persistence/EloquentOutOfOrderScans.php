<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\OutOfOrderScans;
use App\Modules\Attendance\Application\Port\RejectedOutOfOrderScan;
use App\Modules\Attendance\Application\Port\ScanResult;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los fichajes irreconciliables de RN-18, leidos hacia atras de `scan_events`.
 *
 * **No decide nada.** El filtro es por el valor que el camino de fichaje ya
 * escribio —`result = 'rejected_out_of_order'`—, no por una comparacion entre
 * horas: quien comparo fue el agregado, dentro de la transaccion del escaneo y
 * con el turno que habia abierto **entonces**. Repetir aqui esa comparacion
 * seria reinterpretar un hecho pasado con un registro que para esta noche ya
 * puede haber cambiado por una correccion (RN-13).
 *
 * ## La ventana es de `recorded_at`, no de `occurred_at`
 *
 * Y es la diferencia entre abrir la incidencia y perderla. Un fichaje
 * irreconciliable llega, por definicion, de una cola que drena tarde: el
 * elemento que estuvo dias atascado se registra **hoy** con el `occurred_at` de
 * la semana pasada, y una ventana medida sobre el momento real lo dejaria fuera
 * de la pasada de esta noche y de todas las siguientes. Se acota por cuando el
 * servidor lo supo —`recorded_at`, regla dura 9— que es lo que la revision
 * diaria puede prometer: «todo lo que llego desde la ultima vez, se mira».
 *
 * La jornada de la incidencia sigue saliendo del `occurred_at` en la zona del
 * centro (RN-05): el hecho ocurrio cuando ocurrio, y la fila que hay que revisar
 * es la de ese dia, no la de hoy.
 *
 * ## El indice que la sirve
 *
 * `scan_events_flagged_for_review_index`, que es **parcial** —`ON scan_events
 * (recorded_at DESC) WHERE flagged_for_review`— y contiene solo las filas
 * marcadas, una minoria diminuta del historico. Por eso el `WHERE` lleva
 * `flagged_for_review` ademas del resultado: no es una condicion redundante
 * —toda fila de RN-18 nace marcada— sino la que hace alcanzable el indice. Sin
 * ella, la consulta filtraba por `result` y un rango de fechas sin ningun indice
 * detras, y en una instalacion de cuatro anos eso es recorrer la tabla entera
 * cada noche. `ScanLogIndexUsageTest` lo comprueba con `EXPLAIN` sobre datos.
 *
 * Se une con `employees` para devolver el **identificador publico**, igual que
 * {@see EloquentFlaggedScans} y por lo mismo: una clave interna que sube obliga a
 * quien la recibe a saber de que tabla salio.
 */
final readonly class EloquentOutOfOrderScans implements OutOfOrderScans
{
    public function __construct(private ConnectionInterface $connection) {}

    public function outOfOrderBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<object{employee_uuid: string, scan_id: string, occurred_at: string}> $rows */
        $rows = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
            // Primero la condicion del indice parcial: es la que reduce el
            // historico entero a las filas marcadas (ver el docblock).
            ->where('scan_events.flagged_for_review', true)
            // El valor se nombra desde el enum y no se escribe a mano: renombrar
            // el caso dejaria esta consulta buscando algo que ya nadie escribe.
            ->where('scan_events.result', ScanResult::REJECTED_OUT_OF_ORDER->value)
            ->whereBetween('scan_events.recorded_at', [
                $this->toTimestamp($from),
                $this->toTimestamp($to),
            ])
            // Ascendente por el momento REAL: el primero de cada jornada es el
            // que viaja al contexto de la incidencia (ver {@see OutOfOrderScans}),
            // y «el primero» solo significa algo medido en `occurred_at`. Ordenar
            // asi no puede usar el indice, pero ordena un punado de filas: lo que
            // el indice evita es el recorrido, no el `Sort`.
            ->orderBy('scan_events.occurred_at')
            ->select([
                'employees.uuid as employee_uuid',
                'scan_events.scan_id',
                'scan_events.occurred_at',
            ])
            ->get()
            ->all();

        $scans = [];

        foreach ($rows as $row) {
            $scans[] = new RejectedOutOfOrderScan(
                employeeUuid: $row->employee_uuid,
                scanId: $row->scan_id,
                occurredAt: $this->toUtc($row->occurred_at),
            );
        }

        return $scans;
    }

    /**
     * La misma firma y el mismo cuerpo que {@see EloquentScanLog::toUtc()}, a
     * proposito: son dos adaptadores de la misma tabla y una conversion que se
     * escribe distinta en cada uno es una conversion que acaba difiriendo.
     */
    private function toUtc(string|DateTimeInterface $value): DateTimeImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        // Regla dura 3: hacia arriba solo salen instantes en UTC. La columna es
        // `TIMESTAMPTZ` y PostgreSQL la devuelve en la zona de la sesion, que no
        // tiene por que ser la misma manana.
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /** El formato que entiende `TIMESTAMPTZ`, igual que en {@see EloquentScanLog}. */
    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
