<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\IncidentActor;
use App\Modules\Compliance\Application\Port\IncidentBoard;
use App\Modules\Compliance\Application\Port\IncidentBoardPage;
use App\Modules\Compliance\Application\Port\IncidentBoardQuery;
use App\Modules\Compliance\Application\Port\IncidentBoardRow;
use App\Modules\Compliance\Application\Port\IncidentSubject;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * {@see IncidentBoard} sobre PostgreSQL (RF-PA-05).
 *
 * ## Dos consultas planas y ninguna por fila
 *
 * Una para el recuento y otra para la pagina, con **los mismos predicados**
 * construidos una sola vez: si se escribieran dos veces, bastaria tocar una para
 * que la bandeja enseñara doce filas y dijera que hay catorce. El nombre de la
 * persona, el de su departamento, el del responsable y el de quien la cerro
 * salen en la misma consulta que la fila; preguntarlos despues, uno a uno, es el
 * problema N+1 en la pantalla de trabajo diario.
 *
 * ## El alcance entra en el `WHERE`
 *
 * RF-ID-03. Nunca se filtra la pagina ya traida: si se hiciera, `meta.total`
 * describiria a personas que quien pregunta no puede ver. Y un alcance que no
 * alcanza a nadie —un responsable sin departamentos asignados— se traduce en un
 * predicado imposible y **no** en «sin filtro», que es el fallo que convertiria a
 * un responsable recien nombrado en alguien que ve el hotel entero.
 *
 * ## El orden es el de trabajo
 *
 * Severidad primero —`high`, `medium`, `low`— y dentro de ella la mas reciente.
 * Se escribe como `CASE` y no confiando en el orden alfabetico de la cadena,
 * porque alfabeticamente `high` < `low` < `medium` y la bandeja saldria con lo
 * menos urgente en medio. El `id` desempata: sin el, dos incidencias detectadas
 * en la misma pasada —que comparten `detected_at` al microsegundo— podrian salir
 * en distinto orden en dos paginas consecutivas y una de ellas no aparecer nunca.
 *
 * Con `status = 'open'`, que es el caso por omision, el orden y el filtro caen
 * sobre `incidents_open_by_assignee`; para las cerradas se recorre menos de lo
 * que parece porque siempre se pide con filtros.
 *
 * ## Lee tablas de otros modulos
 *
 * `employees`, `departments`, `shift_entries` y `users`. Es la misma excepcion
 * acotada que ya se toman {@see DatabaseIncidentLedger} y
 * {@see DatabaseIncidentNotices}: son las columnas a las que apuntan las claves
 * ajenas de `incidents`, el esquema ya declara esa relacion, y no se importa
 * ningun modelo Eloquent ajeno.
 */
final readonly class DatabaseIncidentBoard implements IncidentBoard
{
    /**
     * Lo que compone una fila, en una sola consulta.
     *
     * El `JOIN` a `employees` es interno y los cuatro restantes son `LEFT`, y la
     * diferencia no es de estilo: una incidencia sin empleado no puede existir
     * —la clave ajena es obligatoria— pero si puede no tener departamento, ni
     * tramo, ni responsable, ni resolutor, y cada uno de esos nulos significa
     * algo distinto y visible.
     */
    private const string SELECT_ROW = <<<'SQL'
        SELECT i.id,
               i.type,
               i.severity,
               i.status,
               i.work_date,
               i.detected_at,
               i.context,
               i.assigned_to_user_id,
               i.resolved_at,
               i.resolved_by_user_id,
               i.resolution_note,
               e.uuid AS employee_uuid,
               e.employee_code,
               e.first_name,
               e.last_name,
               e.site_id,
               e.department_id,
               d.name AS department_name,
               se.uuid AS shift_entry_uuid,
               au.uuid AS assignee_uuid,
               au.name AS assignee_name,
               ru.uuid AS resolver_uuid,
               ru.name AS resolver_name
          FROM incidents i
          JOIN employees e ON e.id = i.employee_id
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN shift_entries se ON se.id = i.shift_entry_id
          LEFT JOIN users au ON au.id = i.assigned_to_user_id
          LEFT JOIN users ru ON ru.id = i.resolved_by_user_id
        SQL;

    /** Lo urgente arriba. Ver el docblock de la clase: el orden alfabetico miente. */
    private const string ORDER = <<<'SQL'
        ORDER BY CASE i.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                 i.detected_at DESC,
                 i.id DESC
        SQL;

    public function __construct(private ConnectionInterface $connection) {}

    public function page(IncidentBoardQuery $query): IncidentBoardPage
    {
        [$where, $bindings] = $this->filters($query);

        /** @var list<object> $counted */
        $counted = $this->connection->select(<<<SQL
            SELECT count(*) AS total
              FROM incidents i
              JOIN employees e ON e.id = i.employee_id
            {$where}
            SQL, $bindings);

        $total = $counted === [] ? 0 : Row::of($counted[0])->int('total');

        $perPage = max(1, $query->perPage);
        $page = max(1, $query->page);

        /** @var list<object> $rows */
        $rows = $this->connection->select(
            self::SELECT_ROW."\n".$where."\n".self::ORDER."\n".'LIMIT ? OFFSET ?',
            [...$bindings, $perPage, ($page - 1) * $perPage],
        );

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = $this->hydrate(Row::of($row));
        }

        return new IncidentBoardPage($entries, $total, $page, $perPage);
    }

    public function row(int $incidentId): ?IncidentBoardRow
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select(self::SELECT_ROW."\n".'WHERE i.id = ?', [$incidentId]);

        return $rows === [] ? null : $this->hydrate(Row::of($rows[0]));
    }

    /**
     * Los predicados comunes a las dos consultas, con sus parametros enlazados.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function filters(IncidentBoardQuery $query): array
    {
        $sql = 'WHERE i.status = ?';
        $bindings = [$query->status->value];

        [$scopeSql, $scopeBindings] = $this->scopeFilter($query->scope);

        $sql .= $scopeSql;
        $bindings = [...$bindings, ...$scopeBindings];

        if ($query->type instanceof IncidentType) {
            $sql .= ' AND i.type = ?';
            $bindings[] = $query->type->value;
        }

        if ($query->severity instanceof IncidentSeverity) {
            $sql .= ' AND i.severity = ?';
            $bindings[] = $query->severity->value;
        }

        if ($query->departmentId !== null) {
            $sql .= ' AND e.department_id = ?';
            $bindings[] = $query->departmentId;
        }

        if ($query->employeeUuid !== null) {
            // Por UUID publico y no por la clave interna: es lo que llega de la
            // URL y lo que la ficha de empleado tiene a mano.
            $sql .= ' AND e.uuid = ?';
            $bindings[] = $query->employeeUuid;
        }

        return [$sql, $bindings];
    }

    /**
     * RF-ID-03 dentro de la consulta.
     *
     * Los tres casos son distintos a proposito y ninguno es «sin filtro»: sin
     * restriccion no se acota, con departamentos se acota a ellos, y **sin
     * ningun departamento no se alcanza a nadie**. Ese ultimo caso existe —un
     * responsable recien creado— y confundirlo con el primero le enseñaria la
     * bandeja del hotel entero.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function scopeFilter(AccessScope $scope): array
    {
        if ($scope->isUnrestricted()) {
            return ['', []];
        }

        if ($scope->reachesNobody()) {
            return [' AND false', []];
        }

        $departmentIds = $scope->departmentIds();
        $placeholders = implode(', ', array_fill(0, \count($departmentIds), '?'));

        return [' AND e.department_id IN ('.$placeholders.')', $departmentIds];
    }

    private function hydrate(Row $row): IncidentBoardRow
    {
        /** @var array<string, int> $context */
        $context = $row->json('context') ?? [];

        return new IncidentBoardRow(
            id: $row->int('id'),
            // El agregado se reconstruye TAL Y COMO ESTA ESCRITO: `restore()` y
            // no `open()`, que decidiria la severidad otra vez y devolveria una
            // fila cerrada a la vida como abierta.
            incident: Incident::restore(
                type: IncidentType::from($row->string('type')),
                severity: IncidentSeverity::from($row->string('severity')),
                status: IncidentStatus::from($row->string('status')),
                employeeUuid: $row->string('employee_uuid'),
                siteId: $row->int('site_id'),
                workDate: $this->isoDate($row->string('work_date')),
                shiftEntryUuid: $row->nullableString('shift_entry_uuid'),
                detectedAt: $row->instant('detected_at'),
                assignedToUserId: $row->nullableInt('assigned_to_user_id'),
                context: $context,
                resolvedAt: $row->nullableInstant('resolved_at'),
                resolvedByUserId: $row->nullableInt('resolved_by_user_id'),
                resolutionNote: $row->nullableString('resolution_note'),
            ),
            subject: new IncidentSubject(
                employeeUuid: $row->string('employee_uuid'),
                employeeCode: $row->string('employee_code'),
                fullName: trim($row->string('first_name').' '.$row->string('last_name')),
                departmentId: $row->nullableInt('department_id'),
                departmentName: $row->nullableString('department_name'),
            ),
            assignedTo: $this->actor($row, 'assignee'),
            resolvedBy: $this->actor($row, 'resolver'),
        );
    }

    /**
     * La cuenta de gestion de una de las dos columnas, o `null` si la columna lo
     * es.
     *
     * Se comprueba el UUID y no el identificador: la clave ajena es `SET NULL`,
     * asi que una cuenta desactivada y borrada deja `assigned_to_user_id` nulo y
     * el `LEFT JOIN` sin fila. La incidencia se queda **sin asignar**, que es un
     * estado legitimo y visible, no un agujero.
     */
    private function actor(Row $row, string $prefix): ?IncidentActor
    {
        $uuid = $row->nullableString($prefix.'_uuid');

        if ($uuid === null) {
            return null;
        }

        return new IncidentActor($uuid, $row->string($prefix.'_name'));
    }

    /**
     * `work_date` es `DATE`, pero el controlador de PostgreSQL puede devolverla
     * con hora segun la version del driver. Se recorta a `Y-m-d`, que es lo que
     * el agregado exige y lo que sale por la API.
     */
    private function isoDate(string $value): string
    {
        return substr($value, 0, 10);
    }
}
