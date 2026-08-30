<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/reports/period` — que informe se pide (**RF-IN-01**, RF-IN-02).
 *
 * ## `from` y `to` son obligatorias, al contrario que en el detalle de jornada
 *
 * Alli tienen omision porque la pantalla se abre sola con el ultimo mes. Aqui no
 * hay pantalla que se abra sola: quien pide un informe de horas ha elegido un
 * periodo, y un informe generado sobre un rango que nadie pidio es exactamente
 * la cifra que despues alguien lleva a una reunion de nomina creyendo que es
 * otra. Ademas evita la ambiguedad de la zona: sin fechas habria que decidir que
 * dia es hoy, y ese calculo solo tiene sentido en la zona del centro.
 *
 * ## Rechaza lo desconocido en lugar de ignorarlo
 *
 * Un `?granularidad=mes` devolveria el informe diario en silencio y quien lo
 * escribio se iria convencido de haber pedido meses. Lo hereda de
 * {@see ValidatesWorkDateRange}, que ademas comprueba que el rango sea
 * **construible** preguntandoselo a {@see DateRange} en lugar de copiar sus
 * limites aqui.
 *
 * ## `department_id` y `employee_uuid` se validan contra la tabla, no contra el
 * alcance
 *
 * Uno inexistente es un `422` —hay una errata que corregir— y uno existente pero
 * fuera del alcance devuelve un informe vacio, no un `403`: son filtros, no la
 * peticion de un recurso ajeno. Mismo criterio que `GET /employees` y que el
 * panel de presencia.
 *
 * ## El alcance no se elige aqui
 *
 * Lo resuelve `ScopeGuard` a partir del token y entra **dentro** de la consulta
 * (RF-ID-03). No hay ningun parametro con el que ampliarlo.
 */
final class GeneratePeriodReportRequest extends FormRequest
{
    use ValidatesWorkDateRange;

    public function authorize(): bool
    {
        return Gate::allows('view', PeriodReport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Obligatorias, al contrario que en el detalle de jornada. El
            // formato y el orden los comprueba `ValidatesWorkDateRange`.
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d'],
            // Los casos salen de los enumerados y no de listas escritas a mano:
            // un valor nuevo entraria aqui solo.
            'granularity' => ['sometimes', 'string', 'in:'.implode(',', ReportGranularity::names())],
            'group_by' => ['sometimes', 'string', 'in:'.implode(',', ReportGrouping::names())],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            'employee_uuid' => ['sometimes', 'uuid', 'exists:employees,uuid'],
            'include_open_shifts' => ['sometimes', 'boolean'],
        ];
    }

    public function toQuery(ScopeGuard $scope): PeriodReportQuery
    {
        return new PeriodReportQuery(
            // RF-ID-03: el alcance lo resuelve el servidor a partir del token y
            // entra en la consulta. Va primero por lo mismo que en el puerto: es
            // la acotacion que quien llama no puede elegir.
            scope: $scope->scopeOf($this->user()),
            range: DateRange::between(
                (string) $this->isoDate('from'),
                (string) $this->isoDate('to'),
            ),
            granularity: $this->granularity(),
            grouping: $this->grouping(),
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            employeeUuid: $this->isoEmployeeUuid(),
            includeOpenShifts: $this->boolean('include_open_shifts'),
        );
    }

    /**
     * `day` por omision: es el grano de la fuente —una fila por empleado y
     * jornada— y el unico que no agrega nada. Suponer «mes» porque es lo que mas
     * se pide seria decidir por quien pregunta.
     */
    private function granularity(): ReportGranularity
    {
        $value = $this->string('granularity')->value();

        return $value === '' ? ReportGranularity::Day : ReportGranularity::from($value);
    }

    /**
     * `employee` por omision, que es la pregunta de partida de RF-IN-01: las
     * horas **de cada persona**. Los agregados de RF-IN-02 se piden.
     */
    private function grouping(): ReportGrouping
    {
        $value = $this->string('group_by')->value();

        return $value === '' ? ReportGrouping::Employee : ReportGrouping::from($value);
    }

    private function isoEmployeeUuid(): ?string
    {
        $value = $this->query('employee_uuid');

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
