<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Reporting\Application\Query\LivePresenceQuery;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Los tres filtros del panel de presencia (**RF-PA-02**).
 *
 * **Rechaza lo desconocido en lugar de ignorarlo**, igual que el listado de
 * plantilla: un `?stauts=present` devolveria otra cosa en silencio y quien lo
 * escribio se iria convencido de haber filtrado.
 *
 * **`status` tiene valor por omision y los otros dos no.** `present` es la
 * pregunta que hace quien abre el panel en un cambio de turno; un listado sin
 * filtro de situacion seria la plantilla entera con una columna de estado, que
 * no es la pantalla que RF-PA-01 describe.
 *
 * **`department_id` se valida contra la tabla, no contra el alcance.** Un
 * departamento inexistente es un `422` —hay una errata que corregir— y uno
 * existente pero fuera del alcance devuelve una lista vacia, no un `403`: es un
 * filtro, no la peticion de un recurso ajeno, y responder `403` convertiria el
 * desplegable del panel en un generador de errores (mismo criterio que
 * `GET /employees`).
 */
final class ListLivePresenceRequest extends FormRequest
{
    use RejectsUnknownInput;

    /** El mismo techo que el listado de plantilla y que el `maxLength` del contrato. */
    public const int MAX_SEARCH_LENGTH = 100;

    public function authorize(): bool
    {
        return Gate::allows('view', PresenceBoard::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            // Los casos salen del enum y no de una lista escrita a mano: un valor
            // nuevo entraria aqui solo, y una lista copiada se quedaria atras sin
            // que nada fallara.
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(PresenceStatus::cases(), 'value'))],
            // `nullable` por los enlaces copiados que arrastran un `?q=` vacio de
            // una busqueda anterior: eso devuelve la lista entera, no un `422`.
            'q' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_SEARCH_LENGTH],
        ];
    }

    public function toQuery(ScopeGuard $scope): LivePresenceQuery
    {
        return new LivePresenceQuery(
            // RF-ID-03: el alcance lo resuelve el servidor a partir del token y
            // entra en la consulta. Va primero por lo mismo que en el puerto: es
            // la acotacion que quien llama no puede elegir.
            scope: $scope->scopeOf($this->user()),
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            search: $this->searchTerm(),
            status: $this->statusFilter(),
        );
    }

    /**
     * Termino ya recortado. «Vacio» y «ausente» son el mismo caso: buscar
     * espacios en blanco casaria con toda la plantilla, que es lo contrario de
     * lo que quiere quien escribe en el cuadro.
     */
    private function searchTerm(): ?string
    {
        $term = trim($this->string('q')->value());

        return $term === '' ? null : $term;
    }

    private function statusFilter(): PresenceStatus
    {
        $status = $this->string('status')->value();

        return $status === '' ? PresenceStatus::Present : PresenceStatus::from($status);
    }
}
