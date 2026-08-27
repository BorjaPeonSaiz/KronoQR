<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

/**
 * Situacion de un quiosco, con los valores que declara el CHECK
 * `devices_chk_status` de la tabla `devices`.
 *
 * Nace con dos y no con cinco a proposito (ver la migracion): el emparejamiento
 * por codigo de RF-PD-06 anadira el suyo en la Fase 5, y ampliar el CHECK de una
 * tabla con decenas de filas es una migracion trivial.
 */
enum DeviceStatus: string
{
    case ACTIVE = 'active';

    case REVOKED = 'revoked';

    /**
     * Un dispositivo revocado no vuelve a autenticarse aunque su token siga sin
     * caducar: la baja tiene efecto en la peticion siguiente, no en 90 dias.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }
}
