<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;

/**
 * El motivo con el que una persona da por revisada una purga no admisible
 * (RL-04, ADR-057 enmienda del 10-10-2026 §6).
 *
 * La revision no puede servir para tapar una purga indebida sin explicar nada:
 * el motivo dice que se investigo y que se hizo. De 10 a 1000 caracteres, sin
 * los espacios de los extremos. Va al asiento de `audit_log` y **nunca** al log
 * tecnico: es texto libre y podria llevar un nombre (regla dura 21).
 */
final readonly class PurgeReviewReason
{
    public const int MIN_LENGTH = 10;

    public const int MAX_LENGTH = 1000;

    private function __construct(public string $text) {}

    /**
     * @throws PurgeReviewRejected
     */
    public static function fromText(string $text): self
    {
        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_LENGTH) {
            throw PurgeReviewRejected::reasonTooShort(self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            throw PurgeReviewRejected::reasonTooLong(self::MAX_LENGTH);
        }

        return new self($trimmed);
    }
}
