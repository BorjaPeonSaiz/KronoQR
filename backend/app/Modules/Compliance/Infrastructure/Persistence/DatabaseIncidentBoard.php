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
 * El recuento es **exacto** —el contrato promete `meta.total` dentro del alcance,
 * y el panel lo anuncia y pagina con el—; lo que se abarata es como se cuenta:
 * sin `JOIN employees` cuando ningun predicado mira la ficha
 * ({@see filtersOnEmployee()}), y sin consulta cuando el alcance no alcanza a
 * nadie.
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
 * El filtro por `status` y el orden completo caen sobre
 * `incidents_status_urgency_index` (`2026_10_08_100100`): la
 * pagina lee sus entradas del indice ya ordenadas, sin `Sort` y sin recorrer la
 * tabla, para cualquier estado; sin el seria `Seq Scan` mas `top-N heapsort` en
 * cada visita, porque `incidents_open_by_assignee` empieza por el responsable y
 * no sirve a una bandeja sin el. **La expresion de {@see ORDER} es la del
 * indice, literal a literal**; si cambia una, tiene que cambiar la otra con una
 * migracion nueva, y `IncidentBoardIndexUsageTest` falla mientras no coincidan.
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
     * Todos los `JOIN` son `LEFT` desde la 2.3.0 (ADR-061 A2): la `discarded_scan`
     * de un descarte del servidor sin atribuir no tiene persona y va por su
     * quiosco (`device_id`); el centro sale entonces del quiosco. Cada nulo
     * —sin persona, sin departamento, sin tramo, sin responsable, sin resolutor—
     * significa algo distinto y visible.
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
               dv.uuid AS device_uuid,
               e.employee_code,
               e.first_name,
               e.last_name,
               COALESCE(e.site_id, dv.site_id) AS site_id,
               e.department_id,
               d.name AS department_name,
               se.uuid AS shift_entry_uuid,
               au.uuid AS assignee_uuid,
               au.name AS assignee_name,
               ru.uuid AS resolver_uuid,
               ru.name AS resolver_name
          FROM incidents i
          LEFT JOIN employees e ON e.id = i.employee_id
          LEFT JOIN devices dv ON dv.id = i.device_id
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN shift_entries se ON se.id = i.shift_entry_id
          LEFT JOIN users au ON au.id = i.assigned_to_user_id
          LEFT JOIN users ru ON ru.id = i.resolved_by_user_id
        SQL;

    /**
     * Lo urgente arriba. Ver el docblock de la clase: el orden alfabetico miente.
     *
     * El `CASE` es, caracter a caracter salvo el alias `i.`, la expresion de
     * `incidents_status_urgency_index` en
     * `database/migrations/2026_10_08_100100_index_incident_board_and_legal_export.php`.
     * No se toca uno sin el otro: con una expresion distinta el indice deja de
     * dar el orden y la bandeja vuelve a ordenar la tabla en memoria.
     */
    private const string ORDER = <<<'SQL'
        ORDER BY CASE i.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                 i.detected_at DESC,
                 i.id DESC
        SQL;

    public function __construct(private ConnectionInterface $connection) {}

    public function page(IncidentBoardQuery $query): IncidentBoardPage
    {
        $perPage = max(1, $query->perPage);
        $page = max(1, $query->page);

        // Un alcance que no alcanza a nadie tiene una respuesta conocida sin
        // preguntar: pagina vacia y total cero (RF-ID-03).
        if (! $query->scope->isUnrestricted() && $query->scope->reachesNobody()) {
            return new IncidentBoardPage([], 0, $page, $perPage);
        }

        [$where, $bindings] = $this->filters($query);

        /** @var list<object> $counted */
        $counted = $this->connection->select(
            'SELECT count(*) AS total FROM incidents i'
            .($this->filtersOnEmployee($query) ? ' LEFT JOIN employees e ON e.id = i.employee_id' : '')
            ."\n".$where,
            $bindings,
        );

        $total = $counted === [] ? 0 : Row::of($counted[0])->int('total');

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
     * Si algun predicado de {@see filters()} mira la ficha (`e.*`).
     *
     * Sin ninguno, el recuento no necesita `employees`: el `LEFT JOIN` no quita
     * ni pone filas —tampoco la incidencia sin persona de ADR-061 A2—, y sin el, el recuento sale solo de
     * `incidents_status_urgency_index` (`Index Only Scan`) en vez de leer cada
     * incidencia del estado para cruzarla. Con `resolved`, que es casi todo el
     * historico, es la diferencia entre el indice y la tabla.
     */
    private function filtersOnEmployee(IncidentBoardQuery $query): bool
    {
        return ! $query->scope->isUnrestricted()
            || $query->departmentId !== null
            || $query->employeeUuid !== null;
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
        /** @var array<string, int|string> $context */
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
                employeeUuid: $row->nullableString('employee_uuid'),
                siteId: $row->int('site_id'),
                workDate: $this->isoDate($row->string('work_date')),
                shiftEntryUuid: $row->nullableString('shift_entry_uuid'),
                detectedAt: $row->instant('detected_at'),
                assignedToUserId: $row->nullableInt('assigned_to_user_id'),
                context: $context,
                resolvedAt: $row->nullableInstant('resolved_at'),
                resolvedByUserId: $row->nullableInt('resolved_by_user_id'),
                resolutionNote: $row->nullableString('resolution_note'),
                deviceUuid: $row->nullableString('device_uuid'),
            ),
            subject: new IncidentSubject(
                employeeUuid: $row->nullableString('employee_uuid'),
                employeeCode: $row->nullableString('employee_code'),
                fullName: $row->nullableString('employee_uuid') === null
                    ? null
                    : trim($row->string('first_name').' '.$row->string('last_name')),
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
     * Se comprueba el UUID y no el identificador porque la columna es nula con
     * toda legitimidad: un departamento sin responsable deja la incidencia **sin
     * asignar**, y una abierta no tiene quien la resolvio. Lo que ya no puede
     * dejarla nula es borrar la cuenta: las dos claves hacia `users` son
     * `RESTRICT` desde `2026_10_14_100100` (ADR-057 §2, RN-13), y una cuenta que
     * figura en una incidencia se desactiva, no se borra.
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
