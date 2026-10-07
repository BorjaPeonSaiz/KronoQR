<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

/**
 * Los dos limites de longitud de una contrasena de gestion (RF-ID-01,
 * RF-ID-10), **escritos una sola vez**.
 *
 * - **Suelo de 8**: ni el `.env` de un cliente puede bajar el minimo de aqui.
 * - **Techo de 72 bytes**: es lo que `bcrypt` llega a leer; mas alla truncaria
 *   en silencio. Recorta tambien el minimo configurado: un minimo por encima
 *   del techo seria una politica imposible de cumplir.
 *
 * Lo usan la politica de las peticiones (`Http/Rule/ManagementPasswordPolicy`),
 * el generador de temporales y el proveedor del modulo.
 */
final class ManagementPasswordLength
{
    public const int FLOOR = 8;

    public const int MAX_BYTES = 72;

    /** El minimo configurado, recortado a [8, 72]. */
    public static function minimum(int $configured): int
    {
        return min(self::MAX_BYTES, max(self::FLOOR, $configured));
    }
}
