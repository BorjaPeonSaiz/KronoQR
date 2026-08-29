<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Sin centro: el alta queda adscrita al de la instalacion (ADR-040).
 */
final readonly class RegisterEmployeeCommand
{
    public function __construct(
        public ?int $departmentId,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $nationalId,
        public string $hiredAt,
        public string $locale,
    ) {}
}
