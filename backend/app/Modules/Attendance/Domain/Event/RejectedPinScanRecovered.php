<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Event;

use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Todos los PIN rechazados de una jornada estan subsanados** (RN-19).
 *
 * Lo emite la revision diaria cuando la jornada entera de esa persona queda
 * subsanada. Lo normal es que no hubiera nada que cerrar —la revision espera a
 * que se cierre la ventana antes de abrir la incidencia—; el caso que lo
 * justifica es la subsanacion que **llega tarde**: el fichaje bueno estaba en la
 * cola offline de otro quiosco cuando la revision abrio la incidencia
 * (doc 01 §4, nota de RN-19).
 *
 * **Attendance no cierra incidencias**: dice que la jornada esta subsanada y
 * `Compliance`, que es quien las tiene, cierra la que siga abierta y deja el
 * asiento (regla dura 6). Por eso, igual que {@see AttendanceAnomalyDetected},
 * los accesores devuelven escalares: el listener no puede nombrar `WorkDate`.
 *
 * Sin datos personales (regla dura 21): la persona por su UUID, y los intentos
 * y los fichajes que los subsanan por su `scan_id`. Esos dos son los que el
 * asiento del cierre deja escritos: un cierre sin persona tiene que poder
 * comprobarse contra `scan_events`.
 */
final readonly class RejectedPinScanRecovered implements DomainEvent
{
    public function __construct(
        public string $employeeUuid,
        public int $siteId,
        public WorkDate $workDate,
        /** El instante de la pasada que lo vio, en UTC. */
        public DateTimeImmutable $detectedAt,
        /**
         * `scan_id` de todos los intentos de la jornada, en su orden.
         *
         * @var list<string>
         */
        public array $attemptScanIds,
        /**
         * `scan_id` de los fichajes que los subsanan, sin repetir.
         *
         * @var list<string>
         */
        public array $recoveringScanIds,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('Una jornada subsanada de RN-19 necesita el UUID de la persona.');
        }

        // Una jornada subsanada tiene al menos un intento y un fichaje que lo
        // subsana: sin ellos no hay nada que cerrar ni nada que asentar.
        if ($attemptScanIds === [] || $recoveringScanIds === []) {
            throw new InvalidArgumentException('Una jornada subsanada de RN-19 necesita sus intentos y los fichajes que los subsanan.');
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

    /** La jornada subsanada, en `Y-m-d` y ya resuelta en la zona del centro (RN-05). */
    public function workDate(): string
    {
        return $this->workDate->isoDate;
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
        return 'attendance.rejected_pin_scan_recovered';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
