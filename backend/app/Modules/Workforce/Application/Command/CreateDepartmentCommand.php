<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Sin centro: el departamento queda en el de la instalacion (ADR-040).
 */
final readonly class CreateDepartmentCommand
{
    public function __construct(
        public string $name,
    ) {}
}
