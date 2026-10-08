<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * La fila de `shift_corrections` que un asiento de correccion exige que exista
 * (RN-13, ADR-057 §4).
 *
 * El asiento lleva la accion, el codigo de motivo y quien la firmo; la fila
 * tiene que decir lo mismo. Sin las dos ultimas, cambiar el autor o el motivo
 * de una correccion —lo que la Inspeccion mira de una correccion— pasaria sin
 * que nada cuadrara mal. Lo que un asiento antiguo no lleve no se exige.
 */
final readonly class ExpectedCorrection
{
    public function __construct(
        public string $action,
        public ?string $reasonCode,
        public ?int $performedByUserId,
        /** Si la fila cuelga de la version que sustituyo a este tramo y no de el. */
        public bool $onReplacement,
    ) {}

    /**
     * @param  list<RecordedCorrection>  $corrections
     */
    public function isMetBy(array $corrections): bool
    {
        return array_any(
            $corrections,
            fn (RecordedCorrection $correction): bool => $correction->action === $this->action
                && ($this->reasonCode === null || $correction->reasonCode === $this->reasonCode)
                && ($this->performedByUserId === null || $correction->performedByUserId === $this->performedByUserId),
        );
    }
}
