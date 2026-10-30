<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Correccion de la fecha de cese de un periodo con baja efectiva (RN-27,
 * ADR-062 §7). `terminatedAt` es una fecha civil `Y-m-d`; `reason`, de 10 a
 * 1 000 caracteres, se guarda con la correccion y no va al asiento.
 */
final readonly class CorrectTerminationDateCommand
{
    public function __construct(
        public string $employeeUuid,
        public string $periodUuid,
        public string $terminatedAt,
        public string $reason,
        public int $correctedByUserId,
    ) {}
}
