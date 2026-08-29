<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Modificacion parcial. `*Given` distingue «no se envio» de «se envio `null`»
 * en los campos anulables. Sin `siteId`: no hay otro centro al que mover a
 * nadie (ADR-040).
 */
final readonly class UpdateEmployeeCommand
{
    public function __construct(
        public string $uuid,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public bool $emailGiven = false,
        public ?int $departmentId = null,
        public bool $departmentGiven = false,
        public ?string $status = null,
        public ?string $locale = null,
    ) {}
}
