<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\EmployeeAttribution;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see EmployeeAttribution} sobre PostgreSQL.
 *
 * **Una sola columna y una sola fila.** Se lee por el UUID publico, que tiene
 * indice unico, y no se trae la ficha: la etiqueta de una metrica no justifica
 * poner el nombre ni el correo de nadie en memoria (regla dura 21).
 *
 * **Incluye a quien esta de baja**, al contrario que la consulta de presencia:
 * un tramo se puede cerrar —o corregir— despues del cese, y esas horas se
 * atribuyen igual a su departamento. Que no aparezcan seria un agujero en el
 * cuadro de negocio justo el mes de una baja.
 */
final readonly class DatabaseEmployeeAttribution implements EmployeeAttribution
{
    public function __construct(private ConnectionInterface $connection) {}

    public function departmentLabelOf(string $employeeUuid): string
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(<<<'SQL'
            SELECT COALESCE(d.name, '') AS department
              FROM employees e
              LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.uuid = ?
             LIMIT 1
            SQL, [$employeeUuid]);

        return $rows === [] ? '' : Row::of($rows[0])->string('department');
    }
}
