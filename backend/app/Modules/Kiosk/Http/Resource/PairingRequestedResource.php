<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\Query\PairingTicket;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `201` de `POST /api/v1/kiosk/pair`: el esquema
 * `PairingRequested`.
 *
 * **Las dos mitades del acto viajan juntas una sola vez.** El `code`, que la
 * tablet enseña, y el `pairing_secret`, que guarda y no enseña a nadie. De los dos
 * se persiste solo el SHA-256: esta respuesta es la unica ocasion en la que
 * existen en claro fuera de la tablet.
 *
 * **El codigo va SIN espacios.** El agrupamiento —«483 921»— es presentacion y lo
 * hace la PWA con {@see PairingTicket::$code} formateado; lo que viaja es lo que
 * se teclea y lo que se hashea. Dos representaciones en el transporte serian dos
 * cadenas que alguien tendria que normalizar en tres sitios.
 *
 * **`poll_interval_seconds` viaja aqui y no compilado en la PWA** (regla dura 13):
 * el limitador del `claim` se dimensiona a partir de esta cadencia, y si el valor
 * viviera en el cliente, ajustarlo obligaria a reinstalar la aplicacion en cada
 * tablet del hotel.
 *
 * @property-read PairingTicket $resource
 */
final class PairingRequestedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PairingTicket $ticket */
        $ticket = $this->resource;

        return [
            'pairing_id' => $ticket->pairingId,
            'pairing_secret' => $ticket->secret,
            'code' => $ticket->code->value,
            'expires_at' => self::utc($ticket->expiresAt),
            'poll_interval_seconds' => $ticket->pollIntervalSeconds,
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
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
