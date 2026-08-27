<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Alta de departamento dentro de un centro.
 */
final readonly class CreateDepartmentCommand
{
    public function __construct(
        public int $siteId,
        public string $name,
    ) {}
}
