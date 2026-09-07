<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\Query\PairingConfirmation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * Serializa el `200` de `POST /api/v1/kiosk/pair/confirm`: el esquema
 * `PairingConfirmed`.
 *
 * **No lleva el token, y no puede llevarlo.** Quien lo necesita es la tablet, que
 * lo recoge en su siguiente sondeo; ponerlo aqui seria dejarlo en el navegador del
 * administrador, que es un sitio donde no hace falta y desde el que se copia.
 *
 * **`reactivated` existe para el texto del panel**, que dice «Se ha reactivado el
 * quiosco Recepcion» en vez de «Creado». Quien sustituye una tablet averiada tiene
 * que ver que el sistema entendio lo que estaba haciendo, y no descubrirlo tres
 * dias despues en un informe con dos quioscos donde hay uno (ADR-028).
 *
 * **`request` existe para que se pueda contrastar lo que se acaba de vincular.**
 * Un codigo de seis digitos se teclea mal con facilidad, y si hay mas de una
 * solicitud viva en la instalacion, un digito cambiado confirma OTRA tablet. Ver
 * la version de la PWA y la hora en que esa tablet pidio el codigo permite darse
 * cuenta en el acto —y desvincular— en lugar de descubrirlo semanas despues en el
 * registro horario. Ninguno de los dos es dato personal (regla dura 21).
 *
 * @property-read PairingConfirmation $resource
 */
final class PairingConfirmedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PairingConfirmation $confirmation */
        $confirmation = $this->resource;
        $device = $confirmation->device;

        if ($device === null || $confirmation->requestedAt === null) {
            // Un rechazo no se serializa aqui y nunca deberia llegar: si llega,
            // fallar en voz alta es lo correcto. Devolver un cuerpo a medias
            // dejaria al panel diciendo que se vinculo un quiosco que no existe.
            throw new RuntimeException('Una confirmacion rechazada no se serializa con PairingConfirmedResource.');
        }

        return [
            'device' => [
                'uuid' => $device->uuid,
                'name' => $device->name,
                'status' => $device->status,
                'reactivated' => $device->reactivated,
            ],
            'request' => [
                'app_version' => $confirmation->requestedAppVersion,
                'requested_at' => self::utc($confirmation->requestedAt),
            ],
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
     * `Identity\Http\Resource\CredentialResource`.
     */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
