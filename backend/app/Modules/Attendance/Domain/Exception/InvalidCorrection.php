<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Exception;

/**
 * Una correccion que no llega a existir porque le falta lo que RN-13 exige.
 *
 * Hoy solo el autor: el motivo lo protege `CorrectionReason` y el momento lo
 * protege `TimeRange::assertUtc()`, cada uno en su objeto.
 */
final class InvalidCorrection extends AttendanceDomainException
{
    public static function withoutAuthor(): self
    {
        return new self('A correction must be signed by a user (RN-13: author, moment and reason).');
    }
}
