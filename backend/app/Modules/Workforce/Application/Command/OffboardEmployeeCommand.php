<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Baja de empleado, en el acto o programada (RF-GP-03, RN-14).
 *
 * `terminatedAt` es una fecha civil en formato `Y-m-d` y no un instante: el cese
 * es un hecho del calendario laboral, no un momento con zona horaria.
 *
 * `reason` es opcional y va al registro de auditoria. Es texto operativo —«fin
 * de contrato de temporada»—, nunca datos de salud ni valoraciones.
 *
 * `applyNow` es «Aplicar ya»: con un cese de hoy, la baja es efectiva en el acto
 * en lugar de programarse (despido con efecto inmediato).
 */
final readonly class OffboardEmployeeCommand
{
    public function __construct(
        public string $uuid,
        public string $terminatedAt,
        public ?string $reason = null,
        public bool $applyNow = false,
    ) {}
}
