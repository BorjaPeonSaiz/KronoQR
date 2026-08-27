<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Domain\Model\Employee;

/**
 * Resultado del alta: la persona y el PIN que se le acaba de emitir (RF-GP-01,
 * RF-ID-09).
 *
 * **Van juntos porque ocurrieron juntos.** El alta emite el PIN en la misma
 * transaccion: un empleado sin PIN no puede fichar por respaldo (RF-AT-11) ni
 * entrar al portal (RL-05), y ese estado no debe poder existir. Devolver solo la
 * ficha obligaria a quien da el alta a restablecer el PIN a continuacion para
 * poder entregarlo, y eso son dos asientos de auditoria para un solo acto.
 */
final readonly class RegisteredEmployee
{
    public function __construct(
        public Employee $employee,
        public IssuedPin $pin,
    ) {}
}
