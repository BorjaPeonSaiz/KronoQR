<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

use DateTimeImmutable;

/**
 * El padron minimo de un centro, listo para viajar al quiosco (esquema
 * `KioskRoster`, RF-KI-03).
 *
 * `generatedAt` no es decoracion: el quiosco guarda el padron cifrado (RL-12) y
 * su pantalla de diagnostico tiene que poder decir de cuando es (RF-KI-08). Sin
 * esa marca, un padron de hace tres semanas es indistinguible de uno de esta
 * mañana, y esa diferencia explica por que el quiosco no reconoce a la persona que
 * entro ayer.
 */
final readonly class KioskRoster
{
    /**
     * @param  list<RosterEntry>  $entries
     */
    public function __construct(
        public DateTimeImmutable $generatedAt,
        public array $entries,
    ) {}

    public function size(): int
    {
        return \count($this->entries);
    }
}
