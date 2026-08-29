<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La forma de cable de una fila de presencia: el esquema `LivePresenceEntry` del
 * contrato.
 *
 * ## Por que existe, y por que no vive en el `Resource`
 *
 * Esa fila sale del servidor por **dos** caminos —el `200` de `GET
 * /api/v1/attendance/live` y el mensaje `presence.updated` del WebSocket— y el
 * contrato promete que son la misma forma, para que el panel pinte la fila con
 * el mismo codigo venga de donde venga. Si la construyeran dos clases, el dia
 * que se añada un campo lo tendria una de las dos y el panel enseñaria filas
 * distintas segun si el tiempo real estaba vivo.
 *
 * El `Resource` vive en `Http` y el difusor en `Infrastructure`, y las fronteras
 * del §1.6 no permiten que el segundo alcance al primero — con razon: un
 * adaptador no depende de la capa HTTP. `Application/Support` es el unico sitio
 * que los dos alcanzan, y es ademas donde tiene sentido: esto no decide nada, es
 * una traduccion.
 *
 * ## Todo instante sale en UTC
 *
 * Regla dura 3. **Aqui no hay hora local y no debe haberla**, al contrario que
 * en el detalle de jornada: la presencia se pinta como «lleva 2 h 15 min
 * dentro», y ese calculo lo hace el panel restando de `meta.generated_at`. Una
 * segunda representacion local seria un campo mas que mantener para una hora que
 * la pantalla no enseña.
 *
 * ## El nombre va al panel, nunca al log
 *
 * `full_name` es un dato personal y su unico destino es la pantalla de una
 * cuenta autorizada (regla dura 21). Ni este array ni el mensaje que lo
 * transporta pueden acabar en un log tecnico ni en `error_events`.
 */
final readonly class PresenceEntryPayload
{
    /**
     * @return array{
     *     employee_uuid: string,
     *     full_name: string,
     *     department: array{id: int, name: string}|null,
     *     status: string,
     *     shift_entry_uuid: string|null,
     *     clocked_in_at: string|null,
     *     origin: string|null,
     *     device: array{uuid: string, name: string}|null,
     * }
     */
    public static function of(PresenceEntry $entry): array
    {
        return [
            'employee_uuid' => $entry->employeeUuid,
            'full_name' => $entry->fullName,
            'department' => $entry->departmentId === null || $entry->departmentName === null
                ? null
                : ['id' => $entry->departmentId, 'name' => $entry->departmentName],
            'status' => $entry->status->value,
            'shift_entry_uuid' => $entry->shiftEntryUuid,
            'clocked_in_at' => self::utc($entry->clockedInAt),
            'origin' => $entry->origin,
            'device' => $entry->deviceUuid === null || $entry->deviceName === null
                ? null
                : ['uuid' => $entry->deviceUuid, 'name' => $entry->deviceName],
        ];
    }

    /**
     * Con microsegundos y sufijo `Z`, que es lo que el esquema `UtcTimestamp`
     * del contrato exige. Se normaliza la zona antes de formatear porque el
     * driver puede devolver el instante con la del servidor: el mismo momento
     * escrito de otra forma seria un desfase de horas en la pantalla.
     */
    public static function utc(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
