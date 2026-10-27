<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Una jornada con PIN rechazado ya subsanado vuelve a tener un intento sin
 * subsanar** (RN-19, enmienda del 27-10-2026 de ADR-057 §2).
 *
 * El caso que lo justifica son dos quioscos sin red: el intento de las 08:00 se
 * subsana desde uno y la revision cierra su incidencia sin persona
 * ({@see RejectedPinScanRecovered}); despues sincroniza otro con un intento de
 * las 22:00 que nadie subsano. El hallazgo nuevo es de la misma jornada y choca
 * con la incidencia cerrada en `one_incident_per_finding`: sin este evento se
 * perdia en silencio.
 *
 * Lo emite la revision diaria junto al hallazgo, cuando la jornada **entera**
 * tiene a la vez algun intento subsanado y alguno sin subsanar. **Attendance no
 * reabre incidencias**: dice que la jornada recayo y `Compliance` reabre la que
 * cerro el sistema, si la hay, y deja el asiento (regla dura 6). La mayoria de
 * las veces no la hay —la subsanacion llego a tiempo y nunca se abrio nada— y
 * el hallazgo abre una incidencia nueva por el camino de siempre.
 *
 * Sin datos personales (regla dura 21): la persona por su UUID, los intentos y
 * los fichajes que subsanan por su `scan_id`, y el contexto del hallazgo, que ya
 * es de escalares.
 */
final readonly class RejectedPinScanRelapsed implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public WorkDate $workDate,
        /** El instante de la pasada que lo vio, en UTC. */
        public DateTimeImmutable $detectedAt,
        /**
         * El `context` del hallazgo nuevo: el que tendra la incidencia reabierta.
         *
         * @var array<string, int|string>
         */
        public array $context,
        /**
         * `scan_id` de todos los intentos de la jornada, en su orden.
         *
         * @var list<string>
         */
        public array $attemptScanIds,
        /**
         * `scan_id` de los fichajes que subsanan los intentos subsanados, sin repetir.
         *
         * @var list<string>
         */
        public array $recoveringScanIds,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Reabrir por RN-19 necesita el UUID de la persona.');
        }

        // Recaer es tener a la vez algo subsanado y algo que no lo esta: sin
        // intentos ni fichaje que subsane no hay nada que reabrir ni que asentar.
        if ($attemptScanIds === [] || $recoveringScanIds === [] || $context === []) {
            throw new InvalidArgumentException('Reabrir por RN-19 necesita los intentos de la jornada, un fichaje que la subsana y los datos del hallazgo.');
        }

        TimeRange::assertUtc('detectedAt', $detectedAt);
    }

    /** Identificador publico de la persona. **Nunca su nombre** (regla dura 21). */
    public function employeeUuid(): string
    {
        return $this->employeeUuid;
    }

    public function siteId(): int
    {
        return $this->siteId;
    }

    /** La jornada, en `Y-m-d` y ya resuelta en la zona del centro (RN-05). */
    public function workDate(): string
    {
        return $this->workDate->isoDate;
    }

    /** @return array<string, int|string> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return list<string> */
    public function attemptScanIds(): array
    {
        return $this->attemptScanIds;
    }

    /** @return list<string> */
    public function recoveringScanIds(): array
    {
        return $this->recoveringScanIds;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'attendance.rejected_pin_scan_relapsed';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
