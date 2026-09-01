<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Contratado frente a real para las dos magnitudes del plan (**ADR-028**).
 *
 * ## Es una descripcion, no una autorizacion
 *
 * Este objeto se calcula **despues** de un alta, nunca antes, y no tiene ningun
 * metodo que responda «¿cabe uno mas?». Que no exista es deliberado: la
 * verificacion de ADR-028 exige por escrito que `POST /api/v1/employees`
 * responda 2xx con `max_employees` superado, y el camino mas corto para
 * incumplirlo es que exista un metodo comodo al que llamar desde el alta.
 *
 * Lo que produce el exceso son tres efectos y ninguno bloquea: aviso persistente
 * en el panel, asiento en `audit_log` con la fecha exacta desde la que se opera
 * por encima del plan, y estas cifras en `license:show`.
 *
 * ## `contracted` es nulo cuando no hay licencia verificada
 *
 * Sin clave activada no hay plan contra el que comparar, y las cifras reales se
 * enseñan igual. Inventar un limite —o tratar la ausencia como cero— convertiria
 * cualquier instalacion recien puesta en marcha en un exceso permanente, con su
 * banner y sus asientos, desde el primer empleado.
 */
final readonly class PlanUsage
{
    public function __construct(
        public PlanLimit $limit,
        public ?int $contracted,
        public int $actual,
    ) {}

    public function isExceeded(): bool
    {
        return $this->contracted !== null && $this->actual > $this->contracted;
    }

    public function excess(): int
    {
        return $this->contracted === null ? 0 : max(0, $this->actual - $this->contracted);
    }
}
