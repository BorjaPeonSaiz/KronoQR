<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Compliance\Application\Port\IncidentBoardQuery;
use App\Modules\Compliance\Domain\Model\Incident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Los cinco filtros y la paginacion de la bandeja (**RF-PA-05**).
 *
 * **Rechaza lo desconocido en lugar de ignorarlo**, igual que el resto de
 * listados: un `?severidad=high` devolveria la bandeja entera en silencio y
 * quien lo escribio se iria convencido de haber filtrado justo lo urgente.
 *
 * **`status` tiene valor por omision y los otros cuatro no.** `open` es la
 * pregunta de quien abre la bandeja; una bandeja sin filtro de situacion seria el
 * historico de cuatro años con una columna de estado, que no es la pantalla que
 * RF-PA-05 describe.
 *
 * **`department_id` y `employee_uuid` se validan contra la tabla, no contra el
 * alcance.** Un identificador inexistente es un `422` —hay una errata que
 * corregir— y uno existente pero fuera del alcance devuelve una pagina vacia, no
 * un `403`: es un filtro, no la peticion de un recurso ajeno, y responder `403`
 * convertiria el desplegable del panel en un generador de errores (mismo criterio
 * que `GET /employees` y `GET /attendance/live`).
 *
 * **Los casos salen de los enums y no de listas escritas a mano**: un tipo nuevo
 * de incidencia entra aqui solo, y una lista copiada se quedaria atras sin que
 * nada fallara.
 */
final class IndexIncidentRequest extends FormRequest
{
    /** El mismo techo que el resto de listados y que el `maximum` del contrato. */
    public const int MAX_PER_PAGE = 100;

    /** Lo que el panel pide sin decir nada. */
    public const int DEFAULT_PER_PAGE = 25;

    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Incident::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:'.self::values(IncidentStatus::cases())],
            'type' => ['sometimes', 'string', 'in:'.self::values(IncidentType::cases())],
            'severity' => ['sometimes', 'string', 'in:'.self::values(IncidentSeverity::cases())],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            'employee_uuid' => ['sometimes', 'uuid', 'exists:employees,uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // El techo es proteccion de recursos: un hotel con turnos continuados
            // puede acumular cientos de incidencias del mismo tipo, y ninguna
            // pantalla las sirve todas de una vez.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function toQuery(ScopeGuard $scope): IncidentBoardQuery
    {
        return new IncidentBoardQuery(
            // RF-ID-03: el alcance lo resuelve el servidor a partir del token y
            // entra en la consulta. Va primero por lo mismo que en el puerto: es
            // la acotacion que quien llama no puede elegir.
            scope: $scope->scopeOf($this->user()),
            status: $this->statusFilter(),
            type: $this->typeFilter(),
            severity: $this->severityFilter(),
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            employeeUuid: $this->employeeFilter(),
            page: $this->has('page') ? $this->integer('page') : 1,
            perPage: $this->has('per_page') ? $this->integer('per_page') : self::DEFAULT_PER_PAGE,
        );
    }

    private function statusFilter(): IncidentStatus
    {
        $status = $this->string('status')->value();

        return $status === '' ? IncidentStatus::Open : IncidentStatus::from($status);
    }

    private function typeFilter(): ?IncidentType
    {
        $type = $this->string('type')->value();

        return $type === '' ? null : IncidentType::from($type);
    }

    private function severityFilter(): ?IncidentSeverity
    {
        $severity = $this->string('severity')->value();

        return $severity === '' ? null : IncidentSeverity::from($severity);
    }

    private function employeeFilter(): ?string
    {
        $uuid = $this->string('employee_uuid')->value();

        return $uuid === '' ? null : $uuid;
    }

    /**
     * @param  list<IncidentStatus|IncidentType|IncidentSeverity>  $cases
     */
    private static function values(array $cases): string
    {
        return implode(',', array_column($cases, 'value'));
    }
}
