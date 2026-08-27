<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Renombrado de un departamento. No incluye el centro a proposito: mover un
 * departamento de centro arrastraria a sus empleados a otra zona horaria (RN-05).
 */
final readonly class RenameDepartmentCommand
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
