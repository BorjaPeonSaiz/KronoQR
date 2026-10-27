<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\RejectedPinScanRecovered;
use App\Modules\Compliance\Application\Command\CloseRecoveredIncidentCommand;
use App\Modules\Compliance\Application\UseCase\CloseRecoveredIncident;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;

/**
 * Cierra la `rejected_pin_scan` de una jornada que la persona subsano (RN-19).
 *
 * El gemelo de {@see OpenIncidentOnAnomalyDetected}: `Attendance` dice que la
 * jornada esta subsanada y este modulo, que tiene las incidencias, cierra la que
 * siga abierta. Solo lee escalares del evento por la misma frontera (doc 02
 * §1.6), y no atrapa nada por el mismo motivo: el aislamiento por hallazgo vive
 * en quien publica, `DetectAttendanceAnomalies::publishRecoveries()`.
 */
final readonly class CloseIncidentOnRejectedPinScanRecovered
{
    public function __construct(private CloseRecoveredIncident $closeIncident) {}

    public function handle(RejectedPinScanRecovered $event): void
    {
        $this->closeIncident->handle(new CloseRecoveredIncidentCommand(
            type: IncidentType::RejectedPinScan->value,
            employeeUuid: $event->employeeUuid(),
            workDate: $event->workDate(),
            recoveredAt: $event->occurredAt(),
            attemptScanIds: $event->attemptScanIds(),
            recoveringScanIds: $event->recoveringScanIds(),
        ));
    }
}
