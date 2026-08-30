<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\IncidentDigest;
use App\Modules\Compliance\Application\Port\IncidentNotice;
use App\Modules\Compliance\Application\Port\IncidentNotices;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Las incidencias pendientes de avisar, agrupadas por responsable (RF-PR-01).
 *
 * La consulta la sirve el indice parcial `incidents_pending_notification`:
 * abiertas, con responsable y sin avisar son unas pocas filas frente al historico
 * de cuatro años.
 *
 * **Solo responsables con la cuenta activa.** Una cuenta desactivada no recibe
 * correo y la incidencia se queda sin avisar —visible en la bandeja, sellada a
 * nulo— hasta que alguien la reasigne. Marcarla como avisada seria peor: nadie la
 * habria leido y nadie volveria a intentarlo.
 *
 * **El nombre del empleado sale de aqui y muere en el correo.** Va dirigido a su
 * responsable, que ya ve su jornada en el panel; sin nombre, el aviso obligaria a
 * buscar un UUID a mano para saber a quien llamar. La regla dura 21 gobierna los
 * logs tecnicos y `error_events`, que viajan al fabricante — no un aviso interno
 * a quien ya esta autorizado.
 */
final readonly class DatabaseIncidentNotices implements IncidentNotices
{
    public function __construct(private ConnectionInterface $connection) {}

    public function pendingByManager(): array
    {
        /** @var list<object{id: int, type: string, severity: string, work_date: string, employee_uuid: string, first_name: string, last_name: string, manager_user_id: int, email: string, locale: string}> $rows */
        $rows = $this->connection->table('incidents')
            ->join('employees', 'employees.id', '=', 'incidents.employee_id')
            ->join('users', 'users.id', '=', 'incidents.assigned_to_user_id')
            ->where('incidents.status', 'open')
            ->whereNull('incidents.notified_at')
            ->where('users.is_active', true)
            ->orderBy('users.id')
            // Lo mas grave primero, y dentro de cada nivel lo mas antiguo: el
            // resumen se recorta cuando hay muchas (ver la notificacion), y lo
            // que se recorta tiene que ser lo menos urgente. `severity` es texto
            // y su orden alfabetico —high, low, medium— no significa nada.
            ->orderByRaw("CASE incidents.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderBy('incidents.detected_at')
            ->select([
                'incidents.id',
                'incidents.type',
                'incidents.severity',
                'incidents.work_date',
                'employees.uuid as employee_uuid',
                'employees.first_name',
                'employees.last_name',
                'users.id as manager_user_id',
                'users.email',
                'users.locale',
            ])
            ->get()
            ->all();

        /** @var array<int, array{email: string, locale: string, incidents: list<IncidentNotice>}> $byManager */
        $byManager = [];

        foreach ($rows as $row) {
            $byManager[$row->manager_user_id] ??= [
                'email' => $row->email,
                'locale' => $row->locale,
                'incidents' => [],
            ];

            $byManager[$row->manager_user_id]['incidents'][] = new IncidentNotice(
                incidentId: $row->id,
                type: IncidentType::from($row->type),
                severity: IncidentSeverity::from($row->severity),
                employeeUuid: $row->employee_uuid,
                employeeName: trim($row->first_name.' '.$row->last_name),
                // `work_date` es DATE y PostgreSQL la devuelve ya como
                // `YYYY-MM-DD`: es una fecha civil, no un instante (RN-05).
                workDate: $row->work_date,
            );
        }

        $digests = [];

        foreach ($byManager as $managerUserId => $group) {
            $digests[] = new IncidentDigest(
                managerUserId: $managerUserId,
                email: $group['email'],
                locale: $group['locale'],
                incidents: $group['incidents'],
            );
        }

        return $digests;
    }

    public function markNotified(array $incidentIds, DateTimeImmutable $at): void
    {
        if ($incidentIds === []) {
            return;
        }

        $this->connection->table('incidents')
            ->whereIn('id', $incidentIds)
            ->update([
                'notified_at' => $at->format('Y-m-d H:i:s.uP'),
                'updated_at' => $at->format('Y-m-d H:i:s.uP'),
            ]);
    }
}
