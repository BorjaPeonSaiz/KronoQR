<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Un escaneo de tarjeta autentica usada antes de su retirada** (RN-20,
 * ADR-047): la fila de `scan_events` rechazada, atribuida a su titular y
 * marcada para revision.
 *
 * Es el hecho, no la conclusion: la revision diaria lo agrupa por persona y
 * jornada. **Sin datos personales** (regla dura 21): el titular por su UUID, el
 * escaneo por su `scan_id`, dos instantes y si la persona esta de baja. Nunca el
 * motivo libre de la revocacion.
 */
final readonly class WithdrawnCredentialScan
{
    public function __construct(
        /** `scan_events.scan_id`: el UUID v7 que genero la tablet (regla dura 8). */
        public string $scanId,
        /** `employee_uuid` del titular de la tarjeta. */
        public string $holderUuid,
        /** Momento real del escaneo, en UTC (regla dura 9). */
        public DateTimeImmutable $occurredAt,
        /** Recepcion en servidor, en UTC. */
        public DateTimeImmutable $recordedAt,
        /** El titular esta de baja (RN-14); si no, lo retirado fue solo la tarjeta. */
        public bool $holderOffboarded,
    ) {
        if ($scanId === '' || $holderUuid === '') {
            throw new InvalidArgumentException('Un escaneo anterior a la retirada necesita su scan_id y su titular.');
        }

        TimeRange::assertUtc('occurredAt', $occurredAt);
        TimeRange::assertUtc('recordedAt', $recordedAt);
    }

    /**
     * `recorded_at − occurred_at` en segundos, **con signo**, como en RN-19.
     */
    public function syncDelaySeconds(): int
    {
        return $this->recordedAt->getTimestamp() - $this->occurredAt->getTimestamp();
    }

    /**
     * `withdrawal` del contexto de la incidencia: `offboarding` si la persona
     * esta de baja, `credential` si lo retirado fue solo la tarjeta.
     */
    public function withdrawal(): string
    {
        return $this->holderOffboarded ? 'offboarding' : 'credential';
    }
}
