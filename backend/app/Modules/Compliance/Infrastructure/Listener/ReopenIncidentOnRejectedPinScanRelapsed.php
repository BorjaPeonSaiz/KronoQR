<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\RejectedPinScanRelapsed;
use App\Modules\Compliance\Application\Command\ReopenRecoveredIncidentCommand;
use App\Modules\Compliance\Application\UseCase\ReopenRecoveredIncident;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;

/**
 * Reabre la `rejected_pin_scan` que cerro el sistema cuando su jornada vuelve
 * a tener un intento sin subsanar (RN-19, enmienda del 27-10-2026 de ADR-057 §2).
 *
 * El gemelo de {@see CloseIncidentOnRejectedPinScanRecovered}: solo lee
 * escalares del evento por la misma frontera (doc 02 §1.6), y no atrapa nada
 * porque el aislamiento por hallazgo vive en quien publica,
 * `DetectAttendanceAnomalies::publishRelapses()`.
 */
final readonly class ReopenIncidentOnRejectedPinScanRelapsed
{
    public function __construct(private ReopenRecoveredIncident $reopenIncident) {}

    public function handle(RejectedPinScanRelapsed $event): void
    {
        $this->reopenIncident->handle(new ReopenRecoveredIncidentCommand(
            type: IncidentType::RejectedPinScan->value,
            employeeUuid: $event->employeeUuid(),
            workDate: $event->workDate(),
            reopenedAt: $event->occurredAt(),
            context: $event->context(),
            attemptScanIds: $event->attemptScanIds(),
            recoveringScanIds: $event->recoveringScanIds(),
        ));
    }
}
