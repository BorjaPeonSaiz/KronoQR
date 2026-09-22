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

    /**
     * De las `$added` unidades que **acaba de añadir una sola operacion**,
     * cuantas quedan por encima del plan.
     *
     * ## Por que hace falta contar esto
     *
     * Porque una importacion de plantilla (RF-GP-05) da de alta a cientos de
     * personas de una vez y produce **un solo asiento** (H-04 de la revision de
     * la 3.8): sin esta cifra, ese asiento diria cuanta gente sobra en total
     * pero no cuanta metio la operacion que lo escribio, que es justo lo que
     * pregunta quien lo lee —«¿cuantos de esos 220 entraron con aquel fichero?»—
     * y lo unico que distingue importar 300 de golpe de importar 30 diez veces.
     *
     * Es un `min` y no una resta: si la instalacion ya estaba en exceso antes de
     * la operacion, las unidades en exceso de esta son **todas** las que
     * entraron, no la diferencia con el tope.
     */
    public function excessAmong(int $added): int
    {
        return min(max(0, $added), $this->excess());
    }

    /**
     * ¿Fue **esta** operacion la que cruzo el umbral?
     *
     * ADR-028 pide distinguir el cruce —la fecha desde la que el cliente opera
     * fuera de contrato, que es la que sostiene una reclamacion— de las altas
     * posteriores en exceso, que dan la magnitud.
     *
     * Vale igual para un alta de una en una (`$added = 1`, y entonces esto es
     * exactamente «el exceso es de uno») y para un lote: con `$added` altas de
     * golpe, el umbral lo cruzo esta operacion si **antes** de ella se cabia en
     * el plan. Calcularlo con el exceso total, como se hacia hasta la 3.8, es
     * correcto solo cuando las unidades entran de una en una: en un lote lo
     * cumplia por casualidad la fila que resultara ser la primera pasada del
     * tope, y nunca la importacion entera.
     */
    public function crossedBy(int $added): bool
    {
        $contracted = $this->contracted;

        return $contracted !== null
            && $this->actual > $contracted
            && $this->actual - max(0, $added) <= $contracted;
    }
}
