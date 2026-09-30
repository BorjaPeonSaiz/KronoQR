<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un **fichaje por PIN rechazado cuyo codigo era de alguien que puede fichar**
 * (RN-19, ADR-043): la fila de `scan_events` con `claimed_employee_id`.
 *
 * Es el hecho, no la conclusion. Si el intento quedo subsanado lo decide
 * {@see PinAttemptRecoveryPolicy} con los
 * fichajes posteriores de esa persona, y la incidencia la agrupa la revision
 * diaria por persona y jornada.
 *
 * **Sin datos personales** (regla dura 21): el dueño del codigo por su UUID, el
 * escaneo por su `scan_id` y dos instantes. Ni el codigo de empleado ni el PIN
 * llegan nunca aqui. **No conoce el reloj** (regla dura 2): los dos instantes
 * llegan resueltos y en UTC.
 */
final readonly class RejectedPinAttempt
{
    public function __construct(
        /** `scan_events.scan_id`: el UUID v7 que genero la tablet (regla dura 8). */
        public string $scanId,
        /** `employee_uuid` del dueño del codigo tecleado. */
        public string $claimantUuid,
        /** Momento real del intento, en UTC (regla dura 9). */
        public DateTimeImmutable $occurredAt,
        /** Recepcion en servidor, en UTC. */
        public DateTimeImmutable $recordedAt,
        /** El intento abrio o encontro el bloqueo de RS-12. */
        public bool $lockout,
    ) {
        if ($scanId === '') {
            throw new InvalidArgumentException('Un intento por PIN rechazado necesita su scan_id.');
        }

        if ($claimantUuid === '') {
            throw new InvalidArgumentException('Un intento por PIN rechazado necesita el UUID del dueño del codigo.');
        }

        TimeRange::assertUtc('occurredAt', $occurredAt);
        TimeRange::assertUtc('recordedAt', $recordedAt);
    }

    /**
     * `recorded_at − occurred_at` en segundos, **con signo**: una cola offline
     * que drena tarde da positivo; un reloj de quiosco adelantado, negativo.
     */
    public function syncDelaySeconds(): int
    {
        return $this->recordedAt->getTimestamp() - $this->occurredAt->getTimestamp();
    }
}
