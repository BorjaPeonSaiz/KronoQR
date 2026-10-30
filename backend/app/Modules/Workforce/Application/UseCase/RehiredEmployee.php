<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Workforce\Domain\Model\Employee;

/**
 * Resultado de la readmision (RN-27): la ficha, su periodo nuevo, la tarjeta
 * nueva pendiente de imprimir y, solo con `reset_pin`, el PIN que se acaba de
 * emitir y que no vuelve a salir.
 */
final readonly class RehiredEmployee
{
    public function __construct(
        public Employee $employee,
        public EmploymentPeriod $period,
        public string $credentialUuid,
        public ?IssuedPin $pin,
    ) {}
}
