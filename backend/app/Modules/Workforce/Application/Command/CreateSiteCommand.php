<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Alta de centro de trabajo.
 */
final readonly class CreateSiteCommand
{
    public function __construct(
        public string $name,
        public string $timezone,
    ) {}
}
