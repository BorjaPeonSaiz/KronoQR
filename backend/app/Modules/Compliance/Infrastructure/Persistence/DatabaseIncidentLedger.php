<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\Port\IncidentTally;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * La tabla `incidents` (RF-PR-01).
 *
 * **La deduplicacion es `ON CONFLICT DO NOTHING`, no un `SELECT` previo.** El
 * indice unico parcial `one_open_incident_per_finding` decide si el hallazgo ya
 * estaba abierto; preguntarlo antes en PHP tendria condicion de carrera con la
 * ejecucion manual del comando mientras el planificador corre. `RETURNING id`
 * devuelve una fila cuando de verdad se inserto y ninguna cuando no, que es
 * exactamente la señal que el caso de uso necesita para decidir si hay algo que
 * auditar.
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
            ON CONFLICT DO NOTHING
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

        return $inserted === [] ? null : $inserted[0]->id;
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
