<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Command;

use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupStepState;

/**
 * Marca de un paso del asistente (RF-PD-03).
 *
 * `actorUuid` es el UUID publico de quien la deja, nunca su correo ni su nombre
 * (regla dura 21). Puede ser `null` solo si el guard cambiara: el endpoint va
 * detras de `auth:sanctum`.
 */
final readonly class RecordSetupStepCommand
{
    public function __construct(
        public SetupStep $step,
        public SetupStepState $state,
        public ?string $actorUuid,
    ) {}
}
