<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resource;

use App\Modules\Compliance\Application\Port\IncidentActor;
use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La forma del esquema `Incident` del contrato, en un solo sitio (RF-PA-05).
 *
 * **Existe porque la misma fila sale por dos puertas**: dentro de la pagina de
 * `GET /api/v1/incidents` y sola en el `200` de
 * `POST /api/v1/incidents/{id}/resolve`. Con la serializacion escrita dos veces,
 * el dia que se añada un campo bastaria olvidarlo en una para que el panel
 * pintara una fila distinta segun de donde viniera — que es justo el caso en el
 * que el panel sustituye la fila recien resuelta por la que devuelve el `POST`.
 *
 * Es el mismo papel que `PresenceEntryPayload` en `Reporting`, y por el mismo
 * motivo.
 *
 * **Ningun instante sale en hora local desde aqui.** Todos van en UTC (regla
 * dura 3) y la antiguedad se calcula contra `meta.generated_at`: la zona del
 * centro viaja una vez en `meta.time_zone` y no repetida en cada campo, porque a
 * diferencia del registro horario aqui no hay tramos de otro centro que puedan
 * tener zona propia.
 */
final readonly class IncidentPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function of(IncidentBoardRow $row): array
    {
        $incident = $row->incident;

        return [
            'id' => $row->id,
            'type' => $incident->type->value,
            'severity' => $incident->severity->value,
            'status' => $incident->status->value,
            'employee' => [
                'uuid' => $row->subject->employeeUuid,
                'employee_code' => $row->subject->employeeCode,
                // El nombre viaja a la pantalla del panel autorizado y jamas al
                // log ni a `audit_log` (regla dura 21).
                'full_name' => $row->subject->fullName,
                'department' => $row->subject->departmentId === null
                    ? null
                    : ['id' => $row->subject->departmentId, 'name' => $row->subject->departmentName],
            ],
            'work_date' => $incident->workDate,
            'shift_entry_uuid' => $incident->shiftEntryUuid,
            'detected_at' => self::utc($incident->detectedAt),
            // Enteros y nada mas: minutos medidos y umbral aplicado. El adaptador
            // ya lo lee tipado, y de la tabla no puede salir otra cosa porque es
            // lo unico que la deteccion escribe.
            'context' => $incident->context,
            'assigned_to' => self::actor($row->assignedTo),
            'resolved_at' => self::utcOrNull($incident->resolvedAt),
            'resolved_by' => self::actor($row->resolvedBy),
            'resolution_note' => $incident->resolutionNote,
        ];
    }

    /**
     * ISO-8601 en UTC con microsegundos, el esquema `UtcTimestamp`.
     *
     * Los seis decimales no son adorno: `incidents.detected_at` guarda con
     * precision de microsegundo, y `incident_resolution_seconds` se calcula
     * contra esa marca.
     */
    public static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function utcOrNull(?DateTimeImmutable $instant): ?string
    {
        return $instant instanceof DateTimeImmutable ? self::utc($instant) : null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function actor(?IncidentActor $actor): ?array
    {
        return $actor instanceof IncidentActor
            ? ['uuid' => $actor->uuid, 'name' => $actor->name]
            : null;
    }
}
