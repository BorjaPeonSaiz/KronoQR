<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un **fichaje de la persona que puede subsanar un PIN rechazado** (RN-19): uno
 * con un desenlace de {@see PinAttemptRecoveryPolicy::recovers()}.
 *
 * Lleva su `scan_id` porque el cierre sin persona tiene que poder decir **que
 * fichaje** lo subsano: el asiento de `system` sin ese dato seria una
 * afirmacion que nadie puede comprobar contra `scan_events`. **Sin datos
 * personales** (regla dura 21): un UUID v7 de la tablet y un instante en UTC.
 */
final readonly class RecoveringScan
{
    public function __construct(
        /** `scan_events.scan_id`: el UUID v7 que genero la tablet (regla dura 8). */
        public string $scanId,
        /** Momento real del fichaje, en UTC (regla dura 9). */
        public DateTimeImmutable $occurredAt,
    ) {
        if ($scanId === '') {
            throw new InvalidArgumentException('Un fichaje que subsana un PIN rechazado necesita su scan_id.');
        }

        TimeRange::assertUtc('occurredAt', $occurredAt);
    }
}
