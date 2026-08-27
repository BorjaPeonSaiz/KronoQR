<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Baja de empleado (RF-GP-03, RN-14).
 *
 * `terminatedAt` es una fecha civil en formato `Y-m-d` y no un instante: el cese
 * es un hecho del calendario laboral, no un momento con zona horaria.
 *
 * `reason` es opcional y va al registro de auditoria. Es texto operativo —«fin
 * de contrato de temporada»—, nunca datos de salud ni valoraciones.
 */
final readonly class OffboardEmployeeCommand
{
    public function __construct(
        public string $uuid,
        public string $terminatedAt,
        public ?string $reason = null,
    ) {}
}
