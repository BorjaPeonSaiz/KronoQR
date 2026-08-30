<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\FlaggedScan;
use App\Modules\Attendance\Application\Port\FlaggedScans;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los escaneos marcados para revision, leidos hacia atras (RN-15, RF-AT-10).
 *
 * **Devuelve el desfase, no el veredicto.** Aqui no hay ningun `>` contra el
 * umbral: quien decide si un desfase pide validacion del responsable es el
 * dominio, con el valor vigente del centro (regla dura 14). Filtrarlo en SQL
 * dejaria la regla escrita en una consulta, donde ni se prueba ni se ve.
 *
 * La consulta la sirve el indice parcial `scan_events_flagged_for_review_index`,
 * que solo contiene las filas marcadas: son una minoria diminuta del historico.
 *
 * Se une con `employees` y con `shift_entries` para devolver **identificadores
 * publicos** —el UUID del empleado y el del tramo— porque son los que el dominio
 * y la API manejan, y porque una clave interna filtrada hacia arriba obliga a
 * quien la recibe a saber de que tabla salio.
 */
final readonly class EloquentFlaggedScans implements FlaggedScans
{
    public function __construct(private ConnectionInterface $connection) {}

    public function flaggedBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<object{scan_id: string, employee_uuid: string, site_id: int|null, occurred_at: string, clock_skew_seconds: int|null, work_date: string|null, shift_entry_uuid: string|null}> $rows */
        $rows = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->leftJoin('shift_entries', 'shift_entries.id', '=', 'scan_events.shift_entry_id')
            ->where('scan_events.flagged_for_review', true)
            ->whereBetween('scan_events.occurred_at', [
                $from->format('Y-m-d H:i:s.uP'),
                $to->format('Y-m-d H:i:s.uP'),
            ])
            ->orderByDesc('scan_events.occurred_at')
            ->select([
                'scan_events.scan_id',
                'employees.uuid as employee_uuid',
                'shift_entries.site_id',
                'scan_events.occurred_at',
                'scan_events.clock_skew_seconds',
                'shift_entries.work_date',
                'shift_entries.uuid as shift_entry_uuid',
            ])
            ->get()
            ->all();

        $scans = [];

        foreach ($rows as $row) {
            $scans[] = new FlaggedScan(
                scanId: $row->scan_id,
                employeeUuid: $row->employee_uuid,
                // Sin tramo no hay centro en el que ocurriera, y sin centro no
                // hay jornada: el caso de uso descarta esas filas. El cero es un
                // marcador que nunca llega a construir nada.
                siteId: $row->site_id ?? 0,
                // `TIMESTAMPTZ` llega con el desplazamiento de la sesion; el
                // dominio solo acepta UTC (regla dura 3).
                occurredAt: (new DateTimeImmutable($row->occurred_at))->setTimezone(new DateTimeZone('UTC')),
                clockSkewSeconds: $row->clock_skew_seconds,
                // `work_date` es DATE: PostgreSQL la devuelve como `YYYY-MM-DD` y
                // asi viaja, sin convertirla a instante. Es una fecha civil, no
                // un momento (RN-05).
                workDate: $row->work_date,
                shiftEntryUuid: $row->shift_entry_uuid,
            );
        }

        return $scans;
    }
}
