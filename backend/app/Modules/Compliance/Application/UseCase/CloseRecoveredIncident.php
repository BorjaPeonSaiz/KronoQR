<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\CloseRecoveredIncidentCommand;
use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Port\IncidentBoard;
use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Illuminate\Database\ConnectionInterface;

/**
 * Cierra la incidencia abierta de una jornada que la persona subsano, y deja su
 * asiento (RN-19, regla dura 6).
 *
 * ## Por que existe
 *
 * La revision de RN-19 espera a que se cierre la ventana de 600 s antes de abrir
 * nada, pero el fichaje que subsana puede llegar **despues**: estaba en la cola
 * offline de otro quiosco cuando la revision miro. Sin este cierre la incidencia
 * se quedaba abierta pidiendo revisar un fichaje que no falta (doc 01 §4, nota
 * de RN-19). Que la jornada esta subsanada lo decide `Attendance`;
 * aqui solo se cierra lo que siga abierto.
 *
 * ## Que no hace
 *
 * - **No toca lo que ya trabajo una persona.** Una incidencia resuelta o
 *   descartada conserva su desenlace y su nota: el puerto solo devuelve las
 *   abiertas, y el `UPDATE ... WHERE status = 'open'` lo garantiza aunque un
 *   responsable la cierre a la vez.
 * - **No cierra la que tuvo bloqueo de PIN** ({@see Incident::closesWhenRecovered()}):
 *   esa la cierra quien hable con la persona y le restablezca el PIN.
 * - **No falla si no hay nada que cerrar**, que es lo normal: la mayoria de las
 *   jornadas subsanadas nunca llegaron a abrir incidencia.
 * - **No observa `incident_resolution_seconds`**, que mide el trabajo de las
 *   personas sobre la bandeja: meter estos cierres lo falsearia.
 *
 * ## Actor `system`, con asiento
 *
 * El cierre lo hace el planificador, asi que el actor es `system`, igual que la
 * apertura ({@see OpenIncident}). La fila y su asiento van en la misma
 * transaccion: un cierre sin traza es una afirmacion sobre el registro de alguien
 * que nadie firma (ADR-027).
 */
final readonly class CloseRecoveredIncident
{
    /** Vocabulario estable del `audit_log`: por que se cerro sin persona. */
    private const string CLOSURE = 'recovered_by_employee';

    public function __construct(
        private IncidentLedger $ledger,
        private IncidentBoard $board,
        private RecordAuditEntry $audit,
        private ConnectionInterface $connection,
    ) {}

    /** Devuelve el identificador de la incidencia cerrada, o `null` si no habia ninguna abierta. */
    public function handle(CloseRecoveredIncidentCommand $command): ?int
    {
        return UseCaseInstrumentation::run('compliance.close_recovered_incident', fn (): ?int => $this->doHandle($command));
    }

    private function doHandle(CloseRecoveredIncidentCommand $command): ?int
    {
        $type = IncidentType::fromDetected($command->type);
        $incidentId = $this->ledger->openWorkDayFindingOf($type, $command->employeeUuid, $command->workDate);

        if ($incidentId === null) {
            return null;
        }

        $row = $this->board->row($incidentId);

        if (! $row instanceof IncidentBoardRow || ! $row->incident->status->isOpen()) {
            return null;
        }

        if (! $row->incident->closesWhenRecovered()) {
            // Con bloqueo de PIN la cierra una persona, despues de hablar con
            // quien lo sufrio (RN-19). No es un fallo de la pasada.
            return null;
        }

        // El dominio decide si este tipo admite el cierre y si sigue abierta.
        $closed = $row->incident->closedAsRecovered($command->recoveredAt);

        return $this->connection->transaction(function () use ($incidentId, $closed, $command): ?int {
            if (! $this->ledger->recordResolution($incidentId, $closed)) {
                // Una persona la cerro entre la lectura y la escritura: manda su
                // desenlace, y no hay nada que auditar aqui.
                return null;
            }

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: AuditActor::system(),
                action: AuditAction::IncidentResolved,
                subject: AuditSubject::of('incident', $incidentId),
                payload: $this->payloadOf($closed, $command),
                occurredAt: $command->recoveredAt,
            ));

            return $incidentId;
        });
    }

    /**
     * Identificadores, fechas y el desenlace. **Ni un nombre** (regla dura 21).
     *
     * Lleva los `scan_id` de los intentos y de los fichajes que los subsanan:
     * un cierre que nadie firma tiene que poder comprobarse contra
     * `scan_events`, y un UUID v7 de la tablet no identifica a nadie.
     */
    private function payloadOf(Incident $closed, CloseRecoveredIncidentCommand $command): AuditPayload
    {
        return AuditPayload::of([
            'employee_uuid' => $closed->employeeUuid,
            'shift_entry_uuid' => $closed->shiftEntryUuid,
            'site_id' => $closed->siteId,
            'work_date' => $closed->workDate,
            'type' => $closed->type->value,
            'severity' => $closed->severity->value,
            'outcome' => $closed->status->value,
            // Sin nota: nadie la escribio. `closure` dice por que se cerro sola.
            'note' => null,
            'closure' => self::CLOSURE,
            'attempt_scan_ids' => $command->attemptScanIds,
            'recovering_scan_ids' => $command->recoveringScanIds,
            'resolution_seconds' => $closed->resolutionSeconds(),
        ]);
    }
}
