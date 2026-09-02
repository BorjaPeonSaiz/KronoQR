<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Domain\ValueObject\SetupSummary;

/**
 * Lo que devuelve cerrar el asistente: el estado final y el resumen accionable
 * (RF-PD-03).
 *
 * Dos objetos y no uno porque responden a dos preguntas distintas —«¿sigue
 * abierto?» y «¿que me queda por hacer?»— y solo la primera vuelve a
 * consultarse despues.
 */
final readonly class CompletedSetup
{
    public function __construct(
        public SetupState $state,
        public SetupSummary $summary,
    ) {}
}
