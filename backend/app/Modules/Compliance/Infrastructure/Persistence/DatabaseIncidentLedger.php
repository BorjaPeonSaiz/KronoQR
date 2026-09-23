<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\Port\IncidentTally;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\Exception\ConflictingFinding;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * La tabla `incidents` (RF-PR-01).
 *
 * **La deduplicacion la decide la restriccion, no un `SELECT` previo.**
 * `one_incident_per_finding` —`(employee_id, work_date, type, shift_entry_id)`,
 * `NULLS NOT DISTINCT` y **sobre todos los estados**— dice si ese hallazgo ya
 * esta escrito; preguntarlo antes en PHP tendria condicion de carrera con la
 * ejecucion manual del comando mientras el planificador corre. `RETURNING id`
 * devuelve una fila cuando de verdad se inserto y ninguna cuando no, que es
 * exactamente la señal que el caso de uso necesita para decidir si hay algo que
 * auditar.
 *
 * **El `ON CONFLICT` nombra la restriccion.** `ON CONFLICT DO NOTHING` a secas
 * silencia cualquier conflicto: el dia que alguien añada otra restriccion a esta
 * tabla, las filas que choquen con ella desaparecerian sin ruido y la deteccion
 * diria que no encontro nada. Nombrandola, un conflicto distinto **falla**, que
 * es lo que se quiere de un error que nadie ha previsto.
 *
 * **Que la restriccion no sea parcial es lo que impide que una incidencia
 * resuelta reviva** cada noche mientras su jornada siga en la ventana. Un tramo
 * cerrado es una fila inmutable: el mismo hallazgo sobre la misma cuadrupla no
 * es un hecho nuevo. Uno que si lo sea entra igual, porque una correccion
 * estrena identificador de tramo (ADR-035).
 *
 * **Traduce identificadores publicos a claves internas**, que es su trabajo: el
 * dominio maneja `employee_uuid` y `shift_entry_uuid` porque son los que salen
 * por la API y los que la Inspeccion puede resolver; `incidents` guarda claves
 * ajenas porque es una tabla.
 *
 * **Lee `employees` y `shift_entries`, que son de otros modulos.** Es la misma
 * excepcion acotada que ya se toma `EloquentWorkDayRepository` con
 * `employees.id`: son las columnas a las que apuntan las claves ajenas de esta
 * tabla, el esquema ya declara esa relacion, y no se importa ningun modelo
 * Eloquent ajeno ni se conoce ninguna otra columna suya.
 */
final readonly class DatabaseIncidentLedger implements IncidentLedger
{
    public function __construct(
        private ConnectionInterface $connection,
        private Clock $clock,
    ) {}

    public function openIfAbsent(Incident $incident): ?int
    {
        $employeeId = $this->employeeIdOf($incident->employeeUuid);

        if ($employeeId === null) {
            // El hallazgo salio de un tramo de esta misma base: si el empleado no
            // esta, algo se ha borrado por debajo. Fallar es preferible a abrir
            // una incidencia sobre nadie.
            throw new RuntimeException(
                'No existe el empleado '.$incident->employeeUuid.' al abrir una incidencia.'
            );
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');

        /** @var list<object{id: int}> $inserted */
        $inserted = $this->connection->select(<<<'SQL'
            INSERT INTO incidents (
                employee_id, work_date, shift_entry_id, type, severity, status,
                assigned_to_user_id, detected_at, context, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?::jsonb, ?, ?)
            ON CONFLICT ON CONSTRAINT one_incident_per_finding DO NOTHING
            RETURNING id
        SQL, [
            $employeeId,
            $incident->workDate,
            $this->shiftEntryIdOf($incident->shiftEntryUuid),
            $incident->type->value,
            $incident->severity->value,
            $incident->assignedToUserId,
            $incident->detectedAt->format('Y-m-d H:i:s.uP'),
            json_encode($incident->context, JSON_THROW_ON_ERROR),
            $now,
            $now,
        ]);

        if ($inserted !== []) {
            return $inserted[0]->id;
        }

        $this->assertItIsTheSameFinding($incident, $employeeId);

        return null;
    }

    /**
     * Cuando el `ON CONFLICT` no inserto, distingue **«ya estaba ese mismo
     * hallazgo»** —silencio, que es lo idempotente— de **«otro hallazgo distinto
     * ocupa esa cuadrupla»**, que se lanza (decision 13d de la ficha 3.11).
     *
     * ## Por que hizo falta
     *
     * La cuadrupla de `one_incident_per_finding` identifica al hallazgo mientras
     * el tipo lo describe entero. `anomalous_pattern` rompio eso: dos indicios
     * distintos de la misma persona y el mismo dia —una coincidencia sistematica
     * y una secuencia imposible, o dos contrapartes distintas— comparten
     * cuadrupla con `shift_entry_id` nulo, y el segundo desaparecia **sin fila,
     * sin asiento, sin fallo y sin log**.
     *
     * **No se repara: se hace visible.** Seguir teniendo una sola incidencia es
     * correcto —la bandeja no puede tener dos filas ahi—; lo que no puede ser es
     * que nadie lo sepa. La pasada lo cuenta como fallo, deja una linea en el log
     * y `pattern_detection_last_failures` sube.
     *
     * La comparacion se hace en SQL con `->>` y no decodificando el JSON en PHP
     * porque el operador ya devuelve `NULL` para la clave ausente, que es
     * exactamente lo que lleva cualquier incidencia que no sea de patron: asi las
     * seis de la revision nocturna comparan `NULL` con `NULL` y siguen en
     * silencio, sin un solo cambio de comportamiento.
     */
    private function assertItIsTheSameFinding(Incident $incident, int $employeeId): void
    {
        /** @var list<object{pattern: string|null, counterpart: string|null}> $existing */
        $existing = $this->connection->select(<<<'SQL'
            SELECT context ->> 'pattern' AS pattern,
                   context ->> 'counterpart_employee_uuid' AS counterpart
              FROM incidents
             WHERE employee_id = ?
               AND work_date = ?
               AND type = ?
               AND shift_entry_id IS NOT DISTINCT FROM ?
        SQL, [
            $employeeId,
            $incident->workDate,
            $incident->type->value,
            $this->shiftEntryIdOf($incident->shiftEntryUuid),
        ]);

        if ($existing === []) {
            // No inserto y tampoco esta: otra transaccion la borro entre las dos
            // sentencias. No pasa —nada borra de `incidents`— y si pasara, lo
            // honesto es el silencio: no hay ningun hallazgo perdido que contar.
            return;
        }

        $pattern = $incident->context['pattern'] ?? null;
        $counterpart = $incident->context['counterpart_employee_uuid'] ?? null;

        if ($existing[0]->pattern === $pattern && $existing[0]->counterpart === $counterpart) {
            return;
        }

        throw ConflictingFinding::onTheSameFinding(
            $incident->employeeUuid,
            $incident->workDate,
            $incident->type->value,
        );
    }

    public function openTally(): array
    {
        /** @var list<object{type: string, severity: string, open: int}> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT type, severity, COUNT(*) AS open
              FROM incidents
             WHERE status = 'open'
          GROUP BY type, severity
          ORDER BY type, severity
        SQL);

        $tallies = [];

        foreach ($rows as $row) {
            $tallies[] = new IncidentTally(
                IncidentType::from($row->type),
                IncidentSeverity::from($row->severity),
                (int) $row->open,
            );
        }

        return $tallies;
    }

    public function recordResolution(int $incidentId, Incident $resolved): bool
    {
        $resolvedAt = $resolved->resolvedAt;

        if ($resolvedAt === null) {
            // No puede pasar: solo `Incident::resolvedBy()` produce un agregado
            // cerrado y siempre pone el instante. Se comprueba porque escribir
            // `resolved_at = NULL` con `status <> 'open'` violaria el `CHECK`
            // `incidents_chk_resolution_is_complete` a mitad de transaccion, y
            // ahi el mensaje seria el de PostgreSQL y no el de nadie.
            throw new RuntimeException('Se ha intentado registrar una resolucion sin instante de cierre.');
        }

        // `WHERE ... AND status = 'open'` ES la garantia, no el `SELECT` que hizo
        // el caso de uso antes: entre leer y escribir cabe otra peticion, y dos
        // responsables trabajando la misma bandeja es el caso normal. La segunda
        // actualiza cero filas y su caso de uso responde `409`.
        //
        // **Cuatro columnas y ni una mas.** El tipo, la severidad, el
        // responsable, el contexto y `detected_at` no se tocan: la incidencia
        // sigue siendo la que se detecto y lo unico que ha pasado es que alguien
        // la ha trabajado.
        $affected = $this->connection->update(<<<'SQL'
            UPDATE incidents
               SET status = ?,
                   resolved_at = ?,
                   resolved_by_user_id = ?,
                   resolution_note = ?,
                   updated_at = ?
             WHERE id = ?
               AND status = 'open'
        SQL, [
            $resolved->status->value,
            $resolvedAt->format('Y-m-d H:i:s.uP'),
            $resolved->resolvedByUserId,
            $resolved->resolutionNote,
            $this->clock->now()->format('Y-m-d H:i:s.uP'),
            $incidentId,
        ]);

        return $affected > 0;
    }

    private function employeeIdOf(string $employeeUuid): ?int
    {
        $id = $this->connection->table('employees')->where('uuid', $employeeUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * El tramo al que apunta la incidencia, si apunta a alguno.
     *
     * Un tramo que ya no esta —corregido y sustituido entre la deteccion y la
     * escritura— deja la incidencia sin referencia en vez de perderla: el hecho
     * detectado sigue siendo cierto y la jornada sigue identificada por empleado
     * y fecha.
     */
    private function shiftEntryIdOf(?string $shiftEntryUuid): ?int
    {
        if ($shiftEntryUuid === null) {
            return null;
        }

        $id = $this->connection->table('shift_entries')->where('uuid', $shiftEntryUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
