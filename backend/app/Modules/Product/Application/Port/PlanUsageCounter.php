<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\PlanLimit;

/**
 * Cuenta lo que hay de verdad en la instalacion para compararlo con el plan
 * (**ADR-028**).
 *
 * ## Cuenta, no autoriza
 *
 * Se invoca **despues** de un alta, desde el observador de `Product`. Ningun
 * caso de uso de `Workforce`, `Identity` o `Kiosk` lo conoce ni lo puede
 * conocer: si lo conocieran, la frontera del §1.6 tendria aristas nuevas y, mas
 * importante, existiria la tentacion de llamarlo antes del alta.
 *
 * ## Que cuenta cada magnitud
 *
 * - **Personas**: las de plantilla **activa**. Quien esta dado de baja no ocupa
 *   plaza del plan, pero su registro se conserva los cuatro años de RL-02: son
 *   dos cosas distintas y contarlas juntas convertiria cualquier hotel de
 *   temporada en un exceso permanente al tercer verano.
 * - **Quioscos**: los dispositivos **no revocados**. Un quiosco averiado y dado
 *   de baja deja libre su plaza en el acto, que es justo lo que hace falta el
 *   dia que hay que sustituirlo.
 */
interface PlanUsageCounter
{
    public function count(PlanLimit $limit): int;
}
