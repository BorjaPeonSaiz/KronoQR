<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

use App\Modules\Kiosk\Domain\ValueObject\PairingCode;
use DateTimeImmutable;

/**
 * Lo que se le devuelve a la tablet que acaba de pedir emparejarse
 * (`POST /api/v1/kiosk/pair`, **RF-PD-06**).
 *
 * **Las dos mitades del acto viajan juntas una sola vez y no vuelven a salir del
 * servidor**: el `code`, que la tablet enseña para que lo lea una persona, y el
 * `secret`, que la tablet guarda y no enseña a nadie. De los dos se persiste solo
 * el SHA-256.
 *
 * **Ninguno autoriza nada.** No son un token: no abren ninguna ruta del producto
 * y no valen sin que un `admin` confirme la solicitud.
 *
 * `pollIntervalSeconds` viaja aqui y no compilado en la PWA (regla dura 13): el
 * limitador del `claim` se dimensiona a partir de esta cadencia, y si el valor
 * viviera en el cliente, ajustarlo obligaria a reinstalar la aplicacion en cada
 * tablet del hotel.
 */
final readonly class PairingTicket
{
    public function __construct(
        public string $pairingId,
        public string $secret,
        public PairingCode $code,
        public DateTimeImmutable $expiresAt,
        public int $pollIntervalSeconds,
    ) {}
}
