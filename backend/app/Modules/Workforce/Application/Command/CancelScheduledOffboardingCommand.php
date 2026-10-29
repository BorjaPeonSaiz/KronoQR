<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Anulacion de una baja programada (RN-14, RF-GP-03). Solo lleva a quien: la
 * fecha que se anula es la que tenga la ficha cuando se lea bloqueada.
 */
final readonly class CancelScheduledOffboardingCommand
{
    public function __construct(public string $uuid) {}
}
