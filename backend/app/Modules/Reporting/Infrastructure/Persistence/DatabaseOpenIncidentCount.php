<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\OpenIncidentCount;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use Illuminate\Database\ConnectionInterface;

/**
 * Cuantas incidencias abiertas alcanza una cuenta (RF-PR-05, RF-ID-03).
 *
 * **Lee `incidents` por SQL, que es de `Compliance`.** Es la misma licencia
 * acotada que ya se toma {@see DatabaseComplianceIncidentLinks} desde la tarea
 * 3.4: `Reporting` es un modelo de lectura y su fuente es la base de datos, no el
 * agregado del otro modulo (doc 02 §1.6, ADR-025). Lo que se lee son dos
 * columnas y un `COUNT`; ninguna regla de `Compliance` se reimplementa aqui, y
 * «abierta» es el mismo `status` que enseña la bandeja.
 *
 * **Por el alcance y no por `assigned_to_user_id`**: son las incidencias de las
 * personas que ese responsable ve en su bandeja. Contar las asignadas daria otro
 * numero y el correo diria algo distinto de lo que enseña la pantalla.
 *
 * **Un alcance que no alcanza a nadie devuelve cero sin consultar.** Con la lista
 * vacia, un `IN ()` es un error de sintaxis en PostgreSQL; y la respuesta
 * correcta es cero, no «todas» (RF-ID-03: la lista vacia significa «nadie», no
 * «sin restriccion»).
 */
final readonly class DatabaseOpenIncidentCount implements OpenIncidentCount
{
    public function __construct(private ConnectionInterface $connection) {}

    public function inScope(AccessScope $scope): int
    {
        if ($scope->reachesNobody()) {
            return 0;
        }

        $query = $this->connection->table('incidents')
            ->join('employees', 'employees.id', '=', 'incidents.employee_id')
            ->where('incidents.status', 'open');

        if (! $scope->isUnrestricted()) {
            $query->whereIn('employees.department_id', $scope->departmentIds());
        }

        return $query->count();
    }
}
