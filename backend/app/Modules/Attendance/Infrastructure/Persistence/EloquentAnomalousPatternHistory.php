<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\AnomalousPatternHistory;
use App\Modules\Attendance\Domain\ValueObject\PatternReviewState;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Lo que la bandeja ya sabe de los indicios de patron de cada persona
 * (RF-PR-06, decision 13c de la ficha 3.11).
 *
 * ## Lee `incidents`, que es una tabla de `Compliance`
 *
 * Es una **excepcion acotada y deliberada**, la misma que ya se toma
 * `DatabaseIncidentLedger` en la direccion contraria con `employees` y
 * `shift_entries`: dos columnas, sin importar ningun modelo Eloquent ajeno y sin
 * conocer ninguna otra parte de aquel modulo. La alternativa —implementar este
 * puerto en `Compliance/Infrastructure`— **no es posible**: el ruleset de
 * Deptrac solo le concede a `Compliance` la capa `Attendance\Domain\Event`, no
 * `Attendance\Application\Port`, y abrir esa arista para un adaptador seria un
 * agujero mucho mayor que esta lectura de dos columnas.
 *
 * ## Que pregunta contesta
 *
 * «De estas personas, ¿a cuales se les esta mirando ya un indicio de este
 * patron, y cuando se cerro el ultimo?». Nada mas: ni el contenido de la
 * incidencia, ni su contraparte, ni quien la trabajo. Con esas dos columnas la
 * politica decide si hay algo **nuevo** que contar.
 *
 * ## `resolved` y `dismissed` cuentan igual
 *
 * Las dos son «alguien lo miro y lo cerro». Un `dismissed` —«llegan juntos en
 * coche»— es justo el caso que esta consulta existe para que no vuelva cada
 * noche; distinguirlo de un `resolved` haria que el descarte durase una noche y
 * el arreglo para siempre, que es al reves de lo que la bandeja necesita.
 *
 * ## Se resuelve con una consulta, no con una por persona
 *
 * `WHERE employees.uuid IN (...)` con todos los de la ventana. La pasada es
 * nocturna, pero una consulta por empleado sobre una plantilla de doscientas
 * personas son doscientos viajes para responder algo que cabe en uno.
 */
final readonly class EloquentAnomalousPatternHistory implements AnomalousPatternHistory
{
    public function __construct(private ConnectionInterface $connection) {}

    public function forEmployees(array $employeeUuids, string $pattern): array
    {
        $states = [];

        foreach ($employeeUuids as $uuid) {
            $states[$uuid] = PatternReviewState::untouched();
        }

        if ($employeeUuids === []) {
            return $states;
        }

        /** @var list<object{employee_uuid: string, open_count: int, last_resolved_at: string|null}> $rows */
        $rows = $this->connection->table('incidents')
            ->join('employees', 'employees.id', '=', 'incidents.employee_id')
            ->whereIn('employees.uuid', $employeeUuids)
            // Por el patron y no por el tipo: `anomalous_pattern` cubre los dos
            // indicios, y una coincidencia sistematica abierta no puede silenciar
            // una secuencia imposible, que es otra cosa y se revisa distinto.
            ->whereRaw("incidents.context ->> 'pattern' = ?", [$pattern])
            ->groupBy('employees.uuid')
            ->select([
                'employees.uuid as employee_uuid',
                $this->connection->raw("COUNT(*) FILTER (WHERE incidents.status = 'open') AS open_count"),
                $this->connection->raw('MAX(incidents.resolved_at) AS last_resolved_at'),
            ])
            ->get()
            ->all();

        foreach ($rows as $row) {
            $states[$row->employee_uuid] = new PatternReviewState(
                hasOpen: $row->open_count > 0,
                lastResolvedAt: $this->toUtc($row->last_resolved_at),
            );
        }

        return $states;
    }

    /**
     * Regla dura 3: hacia arriba solo salen instantes en UTC. La columna es
     * `TIMESTAMPTZ` y PostgreSQL la devuelve en la zona de la sesion.
     */
    private function toUtc(string|DateTimeInterface|null $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
