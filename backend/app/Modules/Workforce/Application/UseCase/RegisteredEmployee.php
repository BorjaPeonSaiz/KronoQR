<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Application\Command\PinProvisioning;
use App\Modules\Workforce\Domain\Model\Employee;

/**
 * Resultado del alta: la persona y el PIN que se le acaba de emitir (RF-GP-01,
 * RF-ID-09).
 *
 * **Van juntos porque ocurrieron juntos.** El alta individual emite el PIN en
 * la misma transaccion: quien la da tiene a la persona delante, y devolver solo
 * la ficha le obligaria a restablecer el PIN a continuacion para poder
 * entregarlo, y eso son dos asientos de auditoria para un solo acto.
 *
 * **`pin` es nulo si y solo si la provision fue diferida**
 * ({@see PinProvisioning::DeferredToCardHandover}), que solo pide la importacion
 * masiva (RF-GP-05): la persona nace con el PIN pendiente y se le emite desde su
 * ficha al entregarle la tarjeta. Quien da un alta individual nunca difiere, y
 * por eso puede tratar un nulo como una incoherencia y no como un caso.
 */
final readonly class RegisteredEmployee
{
    public function __construct(
        public Employee $employee,
        public ?IssuedPin $pin,
    ) {}
}
