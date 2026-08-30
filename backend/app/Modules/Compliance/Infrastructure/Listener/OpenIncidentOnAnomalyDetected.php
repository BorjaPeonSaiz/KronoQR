<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\AttendanceAnomalyDetected;
use App\Modules\Compliance\Application\Command\OpenIncidentCommand;
use App\Modules\Compliance\Application\UseCase\OpenIncident;

/**
 * Convierte un hallazgo de la revision diaria en una incidencia asignada
 * (RF-PR-01, doc 01 §5.1: `Incident` es de `Compliance`).
 *
 * ## Por que un listener y no una llamada
 *
 * `Attendance` no puede importar `Compliance` —el §1.6 no concede esa arista y
 * Deptrac la verifica—, asi que emite y este modulo reacciona. Es la misma via
 * por la que ya llega el asiento de `audit_log`, y el nucleo no sabe que la
 * bandeja de incidencias existe.
 *
 * ## Solo lee escalares del evento
 *
 * `Compliance` alcanza `Attendance\Domain\Event` y **nada mas** de aquel modulo.
 * Por eso el evento expone accesores que devuelven cadenas y enteros: nombrar
 * aqui `AnomalyType` o `WorkDate` seria justo la frontera que este listener
 * existe para respetar. Es la misma tecnica de {@see RecordShiftEntryAudit}.
 *
 * ## Sincrono, y sin transaccion propia
 *
 * La abre el caso de uso, que mete en ella la fila y su asiento. Este listener no
 * envuelve nada: un hallazgo que falla no puede impedir que se abran los demas de
 * la misma pasada.
 */
final readonly class OpenIncidentOnAnomalyDetected
{
    public function __construct(private OpenIncident $openIncident) {}

    public function handle(AttendanceAnomalyDetected $event): void
    {
        $this->openIncident->handle(new OpenIncidentCommand(
            type: $event->type(),
            employeeUuid: $event->employeeUuid(),
            siteId: $event->siteId(),
            workDate: $event->workDate(),
            shiftEntryUuid: $event->shiftEntryUuid(),
            detectedAt: $event->occurredAt(),
            context: $event->context(),
        ));
    }
}
