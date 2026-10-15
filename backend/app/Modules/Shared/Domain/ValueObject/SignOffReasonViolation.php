<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Por que no vale el motivo de una firma de consola ({@see SignOffReason}).
 */
enum SignOffReasonViolation
{
    /** Menos de {@see SignOffReason::MIN_LENGTH} caracteres: no explica nada. */
    case TooShort;

    /** Mas de {@see SignOffReason::MAX_LENGTH} caracteres. */
    case TooLong;
}
