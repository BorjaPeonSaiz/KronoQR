<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Policy;

use InvalidArgumentException;

/**
 * **Techo de asientos `auth.origin_locked` por hora** (ADR-050 §2, dictamen de
 * seguridad B1).
 *
 * Cada asiento de `audit_log` pasa por el candado global de la cadena de hash
 * (ADR-010), el mismo por el que pasa cada fichaje. El bloqueo por origen lo
 * provoca quien ataca, y quien rota direcciones puede abrir uno por direccion:
 * sin techo, un barrido desde miles de origenes meteria miles de escrituras en
 * el camino del cambio de turno.
 *
 * Por encima del techo **el bloqueo se aplica igual**; lo unico que se omite es
 * el asiento. El apunte del log tecnico (con `ip_hash`) y la metrica
 * `outcome="origin_locked"` se siguen escribiendo, asi que el ataque se ve
 * entero en la alerta y en el log aunque la cadena solo guarde los primeros.
 *
 * Hora natural UTC y no ventana deslizante: el contador vive en la cache con una
 * clave por hora y no necesita recordar instantes.
 */
final readonly class OriginLockAuditCeiling
{
    public function __construct(private int $perHour)
    {
        if ($perHour < 0) {
            throw new InvalidArgumentException('El techo de asientos por hora no puede ser negativo.');
        }
    }

    /** Comienzo de la hora UTC a la que pertenece este instante, en segundos Unix. */
    public function hourOf(int $now): int
    {
        return intdiv($now, 3600) * 3600;
    }

    /**
     * Si la apertura numero `$openingsThisHour` de la hora en curso (contando
     * esta) deja asiento. Con techo cero no deja ninguno.
     */
    public function allowsAuditEntry(int $openingsThisHour): bool
    {
        return $openingsThisHour <= $this->perHour;
    }
}
