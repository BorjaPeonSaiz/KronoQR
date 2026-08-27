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
 * sesion, asi que la orden lo declara (`--by=`). La columna es `NOT NULL` cuando
 * hay entrega —CHECK `credentials_chk_delivery_is_signed`— precisamente para que
 * no exista el camino de escribir una entrega sin responsable.
 *
 * **`actorUserId` puede ser `null` y no es lo mismo.** Es quien manejo el
 * sistema. Un comando de consola no tiene sesion y atribuirle la accion a la
 * ultima persona que entro al panel seria falsificar el trail: en `audit_log`
 * queda `system` como actor y el responsable, dentro del asiento.
 */
final readonly class DeliverCredentialCommand
{
    public function __construct(
        public string $credentialUuid,
        /** Quien responde de la entrega (`credentials.delivered_by_user_id`). */
        public int $deliveredByUserId,
        /** Quien manejo el sistema, o `null` si fue consola. */
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
