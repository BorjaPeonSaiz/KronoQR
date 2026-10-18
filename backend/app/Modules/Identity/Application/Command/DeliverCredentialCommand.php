<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

use InvalidArgumentException;

/**
 * Registrar que una tarjeta llego a manos de su titular (RF-QR-06).
 *
 * **`deliveredByUserId` es obligatorio y no se deduce.** RF-QR-06 pide «fecha y
 * responsable», y responsable significa una persona con nombre en `users`, no
 * «el sistema». Por la API es quien tiene la sesion abierta; por consola no hay
 * sesion, asi que la orden lo declara (`--responsible=`). La columna es `NOT NULL` cuando
 * hay entrega —CHECK `credentials_chk_delivery_is_signed`— precisamente para que
 * no exista el camino de escribir una entrega sin responsable.
 *
 * **`actorUserId` puede ser `null` y no es lo mismo.** Es quien manejo el
 * sistema. Desde la 2.3.0 la consola pasa la cuenta de `--responsible`, porque
 * la orden la enseña y quien teclea confirma que es ella (doc 01 §7.2,
 * «Firmas por consola»); `null` queda para quien llame sin nadie que confirme,
 * y el asiento dice entonces `system`, que es la verdad.
 */
final readonly class DeliverCredentialCommand
{
    public function __construct(
        public string $credentialUuid,
        /** Quien responde de la entrega (`credentials.delivered_by_user_id`). */
        public int $deliveredByUserId,
        /** Quien manejo el sistema, o `null` si nadie ha confirmado su firma. */
        public ?int $actorUserId = null,
    ) {
        if ($credentialUuid === '') {
            throw new InvalidArgumentException('Registrar una entrega necesita la credencial.');
        }

        if ($deliveredByUserId < 1) {
            throw new InvalidArgumentException('Registrar una entrega necesita el usuario que responde de ella.');
        }
    }
}
