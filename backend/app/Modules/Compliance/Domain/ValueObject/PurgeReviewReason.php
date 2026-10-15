<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;
use App\Modules\Shared\Domain\ValueObject\SignOffReason;
use App\Modules\Shared\Domain\ValueObject\SignOffReasonViolation;

/**
 * El motivo con el que una persona da por revisada una purga no admisible
 * (RL-04, ADR-057 enmienda del 10-10-2026 §6).
 *
 * La revision no puede servir para tapar una purga indebida sin explicar nada:
 * el motivo dice que se investigo y que se hizo. La regla —de 10 a 1000
 * caracteres, sin los espacios de los extremos— es la de toda firma de consola
 * y vive en {@see SignOffReason}; aqui solo se pone el rechazo propio de la
 * revision, con sus textos. Va al asiento de `audit_log` y **nunca** al log
 * tecnico: es texto libre y podria llevar un nombre (regla dura 21).
 */
final readonly class PurgeReviewReason
{
    public const int MIN_LENGTH = SignOffReason::MIN_LENGTH;

    public const int MAX_LENGTH = SignOffReason::MAX_LENGTH;

    private function __construct(public string $text) {}

    /**
     * @throws PurgeReviewRejected
     */
    public static function fromText(string $text): self
    {
        return new self(SignOffReason::fromText(
            $text,
            static fn (SignOffReasonViolation $violation, int $limit): PurgeReviewRejected => match ($violation) {
                SignOffReasonViolation::TooShort => PurgeReviewRejected::reasonTooShort($limit),
                SignOffReasonViolation::TooLong => PurgeReviewRejected::reasonTooLong($limit),
            },
        )->text);
    }
}
