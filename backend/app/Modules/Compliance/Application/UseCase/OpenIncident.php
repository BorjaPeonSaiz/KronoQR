<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\OpenIncidentCommand;
use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Port\IncidentAssignment;
use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Domain\Exception\ConflictingFinding;
use Illuminate\Database\ConnectionInterface;

/**
 * Abre una incidencia y deja su asiento, o no hace nada si ya estaba abierta
 * (RF-PR-01, regla dura 6).
 *
 * ## Una transaccion, dos escrituras que no pueden separarse
 *
 * La fila de `incidents` y su asiento de `audit_log` van en la misma
 * transaccion. Si el asiento falla, la incidencia no se abre: una incidencia sin
 * traza es una afirmacion sobre las horas de alguien que nadie firma, y es
 * exactamente lo que ADR-027 no admite.
 *
 * ## La idempotencia la resuelve el esquema
 *
 * No hay ningun `SELECT` previo preguntando «¿ya existe?». Lo decide la
 * restriccion `one_incident_per_finding` de `incidents` —mismo empleado, misma
 * jornada, mismo tipo, mismo tramo—, porque un `SELECT` seguido de un `INSERT`
 * tiene condicion de carrera con la ejecucion manual del comando mientras el
 * planificador corre. Cuando ya existia, **tampoco se escribe asiento**: no ha
 * pasado nada nuevo que auditar.
 *
 * **La restriccion NO es parcial, y esa propiedad es la que importa** (O-6 de la
 * revision de la ficha 3.11). Alcanza **todos** los estados, no solo `open`: si
 * solo mirase las abiertas, la incidencia que un responsable descarto ayer
 * reviviria esta noche mientras su jornada siguiera en la ventana, y la bandeja
 * se volveria imposible de vaciar. Aqui estuvo escrito «indice unico parcial»
 * durante dos tareas; era falso y describia justo lo contrario de la garantia.
 *
 * ## Cuando el conflicto **no** es idempotencia
 *
 * Desde la ficha 3.11 el tipo dejo de describir el hallazgo entero: dos indicios
 * distintos de `anomalous_pattern` de la misma persona y el mismo dia comparten
 * cuadrupla. El adaptador los distingue y lanza
 * {@see ConflictingFinding}, que este caso
 * de uso **propaga sin tocar**: quien puede contarlo como fallo de la pasada y
 * dejar la linea de log es quien la lanzo, no quien abre una incidencia suelta.
 *
 * ## Sin persona detras
 *
 * El actor es `system`: lo abre el planificador (ADR-039 y {@see AuditActor}).
 * Decir «usuario desconocido» seria peor que decir la verdad.
 */
final readonly class OpenIncident
{
    public function __construct(
        private IncidentLedger $ledger,
        private IncidentAssignment $assignment,
        private RecordAuditEntry $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * Devuelve el identificador de la incidencia abierta, o `null` si ya lo
     * estaba.
     */
    public function handle(OpenIncidentCommand $command): ?int
    {
        $incident = Incident::open(
            type: IncidentType::fromDetected($command->type),
            employeeUuid: $command->employeeUuid,
            siteId: $command->siteId,
            workDate: $command->workDate,
            shiftEntryUuid: $command->shiftEntryUuid,
            detectedAt: $command->detectedAt,
            // El responsable del departamento (doc 01 §5.5). Si no lo hay, la
            // incidencia se abre igual y queda sin asignar: perder un hallazgo
            // por un hueco de configuracion seria lo contrario de lo que la
            // deteccion existe para hacer.
            assignedToUserId: $this->assignment->responsibleFor($command->employeeUuid),
            context: $command->context,
        );

        return $this->connection->transaction(function () use ($incident): ?int {
            $incidentId = $this->ledger->openIfAbsent($incident);

            if ($incidentId === null) {
                return null;
            }

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: AuditActor::system(),
                action: AuditAction::IncidentOpened,
                subject: AuditSubject::of('incident', $incidentId),
                payload: AuditPayload::of([
                    // Identificadores, minutos y umbrales. **Ni un nombre**
                    // (regla dura 21): el trail viaja entero en la exportacion
                    // legal y su payload se revisa entero.
                    'employee_uuid' => $incident->employeeUuid,
                    'shift_entry_uuid' => $incident->shiftEntryUuid,
                    'site_id' => $incident->siteId,
                    'work_date' => $incident->workDate,
                    'type' => $incident->type->value,
                    'severity' => $incident->severity->value,
                    'assigned_to_user_id' => $incident->assignedToUserId,
                    // Los numeros con los que se abrio: el umbral puede cambiar
                    // despues (RF-PD-07) y sin ellos la incidencia no se puede
                    // defender seis meses mas tarde.
                    'context' => $incident->context,
                ]),
                // El momento de la DETECCION, no el de la jornada: una jornada de
                // anteayer revisada esta madrugada deja un asiento de esta
                // madrugada. La fecha del hecho va dentro, en `work_date`.
                occurredAt: $incident->detectedAt,
            ));

            return $incidentId;
        });
    }
}
