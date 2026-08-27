<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Model;

use App\Modules\Identity\Domain\ValueObject\DeviceStatus;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenLifetime;
use InvalidArgumentException;

/**
 * Un quiosco: la tablet compartida donde se ficha (doc 01 §5.2, RF-ID-04).
 *
 * **El dispositivo no valida su propio token** (doc 01 §5.2). Aqui solo vive el
 * **hash** del token vigente, por lo mismo que en `Credential`: quien lea la
 * tabla no puede suplantar a la tablet. Quien emite y verifica el token es la
 * infraestructura de sesion (Sanctum), y quien decide su vida y su rotacion es
 * {@see DeviceTokenLifetime}.
 *
 * **`status` es la revocacion de verdad.** Un token de quiosco dura 90 dias
 * (§7.3): si la unica forma de retirarlo fuera esperar a que caduque, una tablet
 * robada seguiria fichando durante tres meses. Marcar el dispositivo como
 * revocado lo deja fuera en la peticion siguiente.
 */
final readonly class Device
{
    public function __construct(
        public int $id,
        /** Identificador publico. Es el `device_id` de los logs y de `error_events`. */
        public string $uuid,
        public int $siteId,
        public string $name,
        public DeviceStatus $status,
        /** Hash del token vigente, o `null` si todavia no se ha emparejado. */
        public ?string $tokenHash = null,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('Un dispositivo necesita su clave interna.');
        }

        if ($uuid === '') {
            throw new InvalidArgumentException('Un dispositivo necesita su UUID publico.');
        }

        if ($siteId < 1) {
            throw new InvalidArgumentException('Un dispositivo pertenece siempre a un centro.');
        }
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }
}
