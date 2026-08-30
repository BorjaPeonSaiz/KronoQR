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
 * ## Sincrono, sin transaccion propia y sin `catch`
 *
 * La transaccion la abre el caso de uso, que mete en ella la fila y su asiento.
 * Este listener **no atrapa nada, y es deliberado**: el despachador de Laravel es
 * sincrono, asi que lo que aqui se lance vuelve por la pila de quien publico. Es
 * justo ahi —en `DetectAttendanceAnomalies::publishEach()`— donde vive el
 * aislamiento por hallazgo, porque es quien puede contar los fallos, decidir el
 * codigo de salida del comando y seguir con los demas.
 *
 * Atraparlo tambien aqui solo serviria para que ese contador viera siempre cero:
 * la pasada terminaria «en verde» habiendo perdido incidencias.
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
