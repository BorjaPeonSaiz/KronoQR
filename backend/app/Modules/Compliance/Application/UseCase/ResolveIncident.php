<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Command\ResolveIncidentCommand;
use App\Modules\Compliance\Application\Exception\IncidentNotFound;
use App\Modules\Compliance\Application\Port\IncidentBoard;
use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Compliance\Domain\Exception\IncidentAlreadyClosed;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Una persona da una incidencia por trabajada, con su nota y su traza
 * (**RF-PA-05**, RN-13, regla dura 6).
 *
 * ## Cuatro pasos, y el orden de los cuatro significa algo
 *
 * 1. **Existe** (`404`). Antes que nada, porque quien se equivoca de
 *    identificador tiene que recibir «eso no existe» y no un asiento de intento
 *    fuera de alcance a nombre de nadie.
 * 2. **La alcanza** (`403` con asiento). RF-ID-03: un responsable resuelve lo de
 *    su gente. El asiento lo escribe `ScopeGuard` **antes** de lanzar, que es lo
 *    que exige el escenario «Aislamiento por departamento» del doc 01 §11.
 * 3. **Se cierra y se audita, en una transaccion.** La fila de `incidents` y su
 *    asiento de `audit_log` no pueden separarse: una resolucion sin traza es una
 *    afirmacion sobre las horas de alguien que nadie firma (ADR-027).
 * 4. **Se mide, ya fuera.** El histograma se observa con la transaccion
 *    confirmada, porque perder una metrica es infinitamente mas barato que
 *    convertir una resolucion ya escrita en un `500` que invita a repetirla.
 *
 * ## Esto no cambia ni una hora del registro
 *
 * Resolver una incidencia no cierra ningun turno ni mueve ninguna marca (RN-08).
 * Si hay que rectificar, se rectifica con `PATCH /api/v1/shift-entries/{uuid}`,
 * que exige otro ambito y deja su propia traza. Aqui solo consta que alguien la
 * ha mirado — y por eso este caso de uso no toca `shift_entries` ni
 * `daily_totals`.
 *
 * ## La carrera la decide el `UPDATE`, no un `SELECT`
 *
 * El agregado comprueba que estaba abierta, pero entre esa comprobacion y la
 * escritura cabe otra peticion: dos responsables mirando la misma bandeja es el
 * caso normal. La garantia real es que el repositorio escribe con
 * `WHERE status = 'open'` y dice si escribio; si no escribio, esto lanza la
 * misma excepcion que el dominio y la respuesta es `409` en los dos caminos.
 *
 * ## Actor real, no `system`
 *
 * Al contrario que {@see OpenIncident}, que lo abre el planificador, aqui hay
 * una persona detras y su `users.id` sale del token. Es la mitad de la traza que
 * RN-13 exige: una incidencia cerrada sin autor no explica nada seis meses
 * despues.
 */
final readonly class ResolveIncident
{
    /** Vocabulario estable del `audit_log`, en ingles: el conjunto al que se intento llegar. */
    private const string DATASET = 'incident';

    public function __construct(
        private IncidentBoard $board,
        private IncidentLedger $ledger,
        private RecordAuditEntry $audit,
        private ScopeGuard $scope,
        private IncidentResolutionMetrics $metrics,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws IncidentNotFound cuando no existe
     * @throws IncidentAlreadyClosed cuando ya estaba cerrada
     */
    public function handle(ResolveIncidentCommand $command): IncidentBoardRow
    {
        $row = $this->board->row($command->incidentId);

        if (! $row instanceof IncidentBoardRow) {
            throw IncidentNotFound::withId($command->incidentId);
        }

        $this->scope->ensureReaches(
            $command->scope,
            $row->subject->departmentId,
            self::DATASET,
            $row->subject->employeeUuid,
            ['incident_id' => $command->incidentId],
        );

        $resolved = $row->incident->resolvedBy(
            outcome: $command->outcome,
            userId: $command->resolvedByUserId,
            note: $command->note,
            // Regla dura 2: el instante entra por el puerto y no por `now()`.
            // Sin eso, `incident_resolution_seconds` no se podria probar sin
            // depender del momento en que corre la suite.
            at: $this->clock->now(),
        );

        $this->connection->transaction(function () use ($command, $resolved): void {
            if (! $this->ledger->recordResolution($command->incidentId, $resolved)) {
                // Otra peticion llego antes. Misma respuesta que si lo hubiera
                // visto el agregado: `409`, y ninguna nota escrita.
                throw IncidentAlreadyClosed::inStatus('resolved');
            }

            $this->audit->handle(new RecordAuditEntryCommand(
                actor: AuditActor::user($command->resolvedByUserId),
                action: AuditAction::IncidentResolved,
                subject: AuditSubject::of(self::DATASET, $command->incidentId),
                payload: AuditPayload::of([
                    // Identificadores, fechas y el desenlace. **Ni un nombre**
                    // (regla dura 21): el trail viaja entero en la exportacion
                    // legal y su payload se revisa entero.
                    'employee_uuid' => $resolved->employeeUuid,
                    'shift_entry_uuid' => $resolved->shiftEntryUuid,
                    'site_id' => $resolved->siteId,
                    'work_date' => $resolved->workDate,
                    'type' => $resolved->type->value,
                    'severity' => $resolved->severity->value,
                    'outcome' => $resolved->status->value,
                    // La nota SI va al asiento, y es lo que la distingue de un
                    // log tecnico: es la explicacion que RN-13 exige poder
                    // enseñar, escrita por quien firma. La escribe una persona
                    // sobre el registro horario de otra, asi que el `FormRequest`
                    // la acota a 1000 caracteres.
                    'note' => $resolved->resolutionNote,
                    // Cuanto llevaba esperando. Va al asiento ademas de al
                    // histograma porque el trail tiene que poder responder «esto
                    // se miro a los cuatro dias» sin depender de que Prometheus
                    // conserve la serie.
                    'resolution_seconds' => $resolved->resolutionSeconds(),
                ]),
                // El momento de la RESOLUCION, no el de la deteccion ni el de la
                // jornada: los dos van dentro del payload.
                occurredAt: $resolved->resolvedAt,
            ));
        });

        $this->observe($resolved);

        // Se relee **despues de confirmar** en vez de componer la respuesta a
        // mano: asi lo que el panel pinta es exactamente lo que quedo escrito, y
        // no una reconstruccion que podria diferir el dia que alguien añada una
        // columna.
        $updated = $this->board->row($command->incidentId);

        return $updated instanceof IncidentBoardRow
            ? $updated
            : throw IncidentNotFound::withId($command->incidentId);
    }

    /**
     * `incident_resolution_seconds{type}` (doc 02 §8.2).
     *
     * Fuera de la transaccion y sin poder romperla: cuando se llega aqui la
     * incidencia esta cerrada y su asiento escrito. El adaptador ademas se traga
     * cualquier fallo de Redis, igual que el de las correcciones manuales.
     */
    private function observe(Incident $resolved): void
    {
        $seconds = $resolved->resolutionSeconds();

        if ($seconds === null) {
            return;
        }

        $this->metrics->resolutionObserved($resolved->type, $seconds);
    }
}
