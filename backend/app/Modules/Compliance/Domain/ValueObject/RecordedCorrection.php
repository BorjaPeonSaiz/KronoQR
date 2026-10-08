<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Una fila de `shift_corrections` tal y como esta escrita: que se hizo, por que
 * y quien lo firmo (RN-13). Lo que el asiento de la correccion dice de las tres
 * cosas tiene que coincidir con ella (ADR-057 §4).
 */
final readonly class RecordedCorrection
{
    public function __construct(
        public string $action,
        public string $reasonCode,
        public int $performedByUserId,
    ) {}
}
