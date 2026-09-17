<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\ComplianceIncidentLinks;
use App\Modules\Reporting\Domain\ValueObject\ComplianceIncidentLink;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * La incidencia de la bandeja que corresponde a cada hallazgo (RF-PA-06, paso 4
 * de la ficha).
 *
 * ## Una consulta para todo el lote
 *
 * Se pasan las ternas `(empleado, jornada, tipo)` y vuelven las que existen. Un
 * `SELECT` por hallazgo seria un `N+1` contra `incidents` en el camino de una
 * pantalla que RRHH abre cada mañana (RNF-P-02), y con cuatro semanas de ventana
 * pueden ser cientos.
 *
 * El predicado se monta como un `IN` sobre tuplas —`(uuid, fecha, tipo) IN ((?,
 * ?::date, ?), …)`— y el `JOIN` con `employees` va por su clave primaria; el
 * acceso a `incidents` lo resuelve el planificador con los indices de esa tabla.
 * **Todo lo que viene del hallazgo viaja enlazado**: lo unico que se concatena son
 * los `?`, que se cuentan a partir del numero de ternas.
 *
 * ## Cual se devuelve cuando hay mas de una
 *
 * La **abierta**, y si no hay ninguna abierta, la mas reciente. `long_shift` cubre
 * en la bandeja dos reglas distintas —RN-08 sobre un tramo suelto y RN-11 sobre el
 * dia— asi que la misma jornada puede tener dos, una por tramo. La que quien
 * revisa necesita abrir es la que sigue pendiente; enseñarle una ya resuelta
 * mientras hay otra abierta le haria creer que el asunto esta cerrado.
 *
 * ## `employees` entra en el `JOIN` porque `incidents` guarda el id interno
 *
 * Y el hallazgo trae el **uuid publico**, que es el unico identificador de una
 * persona que sale de este modulo (regla dura 21). La traduccion ocurre aqui, en
 * la persistencia, que es donde viven los identificadores internos.
 */
final readonly class DatabaseComplianceIncidentLinks implements ComplianceIncidentLinks
{
    public function __construct(private ConnectionInterface $connection) {}

    public function linksFor(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $tuples = [];

        /** @var list<scalar> $bindings */
        $bindings = [];

        foreach ($keys as $key) {
            $tuples[] = '(?, ?::date, ?)';
            $bindings[] = $key['employee_uuid'];
            $bindings[] = $key['work_date'];
            $bindings[] = $key['type'];
        }

        $rows = $this->connection->select($this->sql($tuples), $bindings);

        $links = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);

            $key = $reader->string('employee_uuid')
                .'|'.substr($reader->string('work_date'), 0, 10)
                .'|'.$reader->string('type');

            $links[$key] = new ComplianceIncidentLink($reader->int('id'), $reader->string('status'));
        }

        return $links;
    }

    /**
     * @param  list<string>  $tuples  marcadores `(?, ?::date, ?)`, uno por terna
     */
    private function sql(array $tuples): string
    {
        $in = implode(', ', $tuples);

        return <<<SQL
            SELECT DISTINCT ON (e.uuid, i.work_date, i.type)
                   e.uuid  AS employee_uuid,
                   i.work_date,
                   i.type,
                   i.id,
                   i.status
              FROM incidents i
              JOIN employees e ON e.id = i.employee_id
             WHERE (e.uuid::text, i.work_date, i.type) IN ({$in})
             -- Abierta primero y, en empate, la mas reciente: es la que quien
             -- revisa tiene que trabajar. El orden empieza por las mismas
             -- expresiones del `DISTINCT ON`, que es lo que PostgreSQL exige.
             ORDER BY e.uuid, i.work_date, i.type, (i.status = 'open') DESC, i.detected_at DESC, i.id DESC
            SQL;
    }
}
