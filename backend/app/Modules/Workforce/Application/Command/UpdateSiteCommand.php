<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Modificacion parcial de un centro. Cambiar `timezone` cambia el calculo de las
 * jornadas siguientes (RN-05), asi que es un cambio auditable.
 */
final readonly class UpdateSiteCommand
{
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $timezone = null,
    ) {}
}
