<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Sin identificador: el centro es el de la instalacion (ADR-040).
 */
final readonly class UpdateSiteCommand
{
    public function __construct(
        public ?string $name = null,
        public ?string $timezone = null,
    ) {}
}
