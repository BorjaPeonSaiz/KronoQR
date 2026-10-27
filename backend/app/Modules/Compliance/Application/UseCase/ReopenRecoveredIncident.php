<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Command\ReopenRecoveredIncidentCommand;
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
use App\Modules\Shared\Domain\Support\UtcInstant;
use Illuminate\Database\ConnectionInterface;

/**
 * Reabre la incidencia que **cerro el sistema** cuando su jornada vuelve a
 * tener un intento sin subsanar, y deja su asiento (RN-19, enmienda del
 * 27-10-2026 de ADR-057 §2, regla dura 6).
 *
 * ## Por que existe
 *
 * El gemelo de {@see CloseRecoveredIncident}. Con dos quioscos sin red, la
 * subsanacion del intento de las 08:00 puede llegar —y cerrar la incidencia—
 * antes que otro intento de las 22:00 que nadie subsano. Ese hallazgo es de la
 * misma jornada, choca con `one_incident_per_finding` y {@see OpenIncident} no
 * abre nada: sin esta reapertura, un fichaje que puede faltar del registro
 * desaparecia en silencio. Que la jornada recayo lo decide `Attendance`; aqui
 * solo se reabre lo que cerro el sistema.
 *
 * ## Que no hace
 *
 * - **No toca lo que trabajo una persona.** El puerto solo devuelve lo que
 *   cerro el sistema —`resolved`, sin autor ni nota— y el `UPDATE` lo vuelve a
 *   exigir por si una persona la trabajo entre medias. El *trigger*
 *   `incidents_guard_transition` dice lo mismo.
 * - **No falla si no hay nada que reabrir**, que es lo normal: la subsanacion
 *   llego a tiempo y nunca se abrio nada, o la incidencia sigue abierta.
 * - **No cambia `detected_at`** ni avisa por correo: el primer aviso no se
 *   reescribe (ADR-057 §2). La incidencia vuelve a la bandeja y a
 *   `incidents_open`, y su momento queda en el asiento.
 *
 * ## Actor `system`, con asiento
 *
 * La fila y su asiento van en la misma transaccion. El asiento lleva el
 * contexto anterior y el nuevo, y los `scan_id` de los intentos y de los
 * fichajes que subsanaron: quien audite la reapertura puede comprobarla contra
 * `scan_events`.
 */
final readonly class ReopenRecoveredIncident
{
    /** Vocabulario estable del `audit_log`: por que se reabrio sin persona. */
    private const string REOPENING = 'unrecovered_attempt_after_recovery';

    public function __construct(
        private IncidentLedger $ledger,
        private IncidentBoard $board,
        private RecordAuditEntry $audit,
        private ConnectionInterface $connection,
    ) {}

    /** Devuelve el identificador de la incidencia reabierta, o `null` si no habia ninguna que reabrir. */
    public function handle(ReopenRecoveredIncidentCommand $command): ?int
    {
        return UseCaseInstrumentation::run('compliance.reopen_recovered_incident', fn (): ?int => $this->doHandle($command));
    }

    private function doHandle(ReopenRecoveredIncidentCommand $command): ?int
    {
        $type = IncidentType::fromDetected($command->type);
        $incidentId = $this->ledger->systemClosedWorkDayFindingOf($type, $command->employeeUuid, $command->workDate);

        if ($incidentId === null) {
            return null;
        }

        $row = $this->board->row($incidentId);

        if (! $row instanceof IncidentBoardRow || ! $row->incident->wasClosedBySystem()) {
            return null;
        }

        $closed = $row->incident;

        // El dominio decide si se puede reabrir: solo el tipo que se cierra sin
        // persona, y solo si lo cerro el sistema.
        $reopened = $closed->reopenedBySystem($command->context);

        return $this->connection->transaction(function () use ($incidentId, $closed, $reopened, $command): ?int {
            if (! $this->ledger->recordReopening($incidentId, $reopened)) {
                // Una persona la trabajo entre la lectura y la escritura: manda
                // su desenlace, y no hay nada que auditar aqui.
                return null;
            }

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: AuditActor::system(),
                action: AuditAction::IncidentReopened,
                subject: AuditSubject::of('incident', $incidentId),
                payload: $this->payloadOf($closed, $reopened, $command),
                occurredAt: $command->reopenedAt,
            ));

            return $incidentId;
        });
    }

    /**
     * Identificadores, fechas y los dos contextos. **Ni un nombre** (regla dura 21).
     */
    private function payloadOf(Incident $closed, Incident $reopened, ReopenRecoveredIncidentCommand $command): AuditPayload
    {
        return AuditPayload::of([
            'employee_uuid' => $reopened->employeeUuid,
            'shift_entry_uuid' => $reopened->shiftEntryUuid,
            'site_id' => $reopened->siteId,
            'work_date' => $reopened->workDate,
            'type' => $reopened->type->value,
            'severity' => $reopened->severity->value,
            'outcome' => $reopened->status->value,
            'reopening' => self::REOPENING,
            // El cierre que se deshace: cuando fue, y con que hechos.
            'previous_resolved_at' => UtcInstant::format($closed->resolvedAt),
            'previous_context' => $closed->context,
            'context' => $reopened->context,
            'attempt_scan_ids' => $command->attemptScanIds,
            'recovering_scan_ids' => $command->recoveringScanIds,
        ]);
    }
}
