<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa un quiosco: el esquema `Device`.
 *
 * Lo usan `GET /api/v1/devices` —dentro de {@see DeviceListResource}— y el `200`
 * de `POST /api/v1/devices/{uuid}/unpair`, que devuelve el dispositivo **ya
 * revocado** para que el panel repinte la fila sin volver a pedir la lista.
 *
 * **Nunca lleva el token ni su hash**, ni lo llevara: quien lo viera tendria la
 * mitad del trabajo hecho para suplantar a un dispositivo. Tampoco la clave
 * interna, ni el `site_id`, que con un centro por instalacion (ADR-040) es
 * siempre el mismo.
 *
 * **Los instantes salen en UTC con sufijo `Z`** (regla dura 3). La conversion a la
 * zona del centro la hace el panel.
 *
 * @property-read DeviceSummary $resource
 */
final class DeviceResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeviceSummary $device */
        $device = $this->resource;

        return [
            'uuid' => $device->uuid,
            'name' => $device->name,
            'status' => $device->status,
            'app_version' => $device->appVersion,
            'last_seen_at' => self::utc($device->lastSeenAt),
            'pending_queue_size' => $device->pendingQueueSize,
            'paired_at' => self::utc($device->pairedAt),
        ];
    }

    /**
     * El formato `UtcTimestamp` del contrato: sufijo `Z`, nunca un desfase
     * explicito (regla dura 3).
     *
     * `setTimezone(UTC)` aunque el instante ya venga en UTC —lo esta, porque
     * `APP_TIMEZONE=UTC` y las columnas son `TIMESTAMPTZ`—: sin la conversion, un
     * dia en que alguien cambie esa configuracion la `Z` seria una mentira, y una
     * hora mal etiquetada no se detecta a ojo. Es el mismo criterio que documenta
     * `Identity\\Http\\Resource\\CredentialResource`.
     */
    private static function utc(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
