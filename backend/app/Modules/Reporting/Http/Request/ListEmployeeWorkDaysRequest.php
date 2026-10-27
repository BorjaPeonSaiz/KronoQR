<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Application\Query\EmployeeWorkDayRange;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/employees/{uuid}/workdays` — el rango de jornadas que se consulta
 * (RF-PA-03).
 *
 * ## Los dos parametros son opcionales y **no se completan aqui**
 *
 * Resolver la omision necesita saber que dia es hoy **en la zona del centro del
 * empleado** (RN-04), y eso solo se sabe despues de buscarlo. Lo hace
 * {@see ReadEmployeeWorkDays}, que tiene
 * el puerto `Clock` y el de la consulta. Si el `FormRequest` pusiera un valor por
 * omision, lo pondria con la zona del servidor, y a las 00:30 de Madrid eso deja
 * fuera la jornada en curso justo en el turno de noche.
 *
 * ## Que se valida y que no
 *
 * Lo dice {@see ValidatesWorkDateRange}, que es donde viven las reglas del rango
 * —las mismas para las tres peticiones que piden un rango de jornadas— y el
 * rechazo de los campos desconocidos. Lo unico propio de esta es la policy.
 */
final class ListEmployeeWorkDaysRequest extends FormRequest
{
    use ValidatesWorkDateRange;

    /**
     * Jornadas por pagina si no se pide otra cosa: **el mismo mes** que el
     * panel abre de serie ({@see ReadEmployeeWorkDays::DEFAULT_DAYS}), de modo
     * que la pantalla por omision cabe en una pagina y no cambia para quien no
     * pagina.
     */
    public const int DEFAULT_PER_PAGE = ReadEmployeeWorkDays::DEFAULT_DAYS;

    /** El techo del contrato (`WorkDayPerPage`). */
    public const int MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return Gate::allows('view', WorkDayJournal::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->workDateRangeRules(),
            // Por jornadas enteras. Mismo techo que el resto de listas
            // paginadas del contrato; el valor por omision es el mes del panel.
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function page(): int
    {
        return $this->has('page') ? $this->integer('page') : 1;
    }

    public function perPage(): int
    {
        return $this->has('per_page') ? $this->integer('per_page') : self::DEFAULT_PER_PAGE;
    }

    public function toQuery(string $employeeUuid): EmployeeWorkDayRange
    {
        return new EmployeeWorkDayRange(
            employeeUuid: $employeeUuid,
            from: $this->isoDate('from'),
            to: $this->isoDate('to'),
        );
    }
}
