<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Application\Query\ComplianceSummaryCriteria;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleName;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummary;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/compliance/summary` — que periodo y que filtros (**RF-PA-06**).
 *
 * ## `from` y `to` son opcionales, al contrario que en el informe por periodo
 *
 * Esta pantalla **se abre sola**: quien entra quiere ver si hay algo que revisar,
 * y obligarle a elegir fechas antes de enseñarle nada convertiria una bandeja en
 * un formulario. La omision —los 28 dias que terminan hoy— la resuelve el caso de
 * uso, que es quien sabe que dia es hoy **en la zona del centro** (ADR-040): un
 * `FormRequest` lo resolveria con la zona del servidor, y a las 00:30 de Madrid
 * eso deja fuera la jornada en curso justo en el turno de noche.
 *
 * ## El techo del rango tampoco se comprueba aqui
 *
 * `REPORTING_COMPLIANCE_MAX_RANGE_DAYS` lo aplica el caso de uso sobre el rango ya
 * resuelto, y tiene que ser asi: con solo `from`, el `to` lo pone «hoy», y un
 * techo que mirase unicamente el par completo dejaria pasar `?from=2020-01-01`.
 * Lo que si se comprueba aqui, heredado de {@see ValidatesWorkDateRange}, es que
 * el rango sea **construible** —el orden de las fechas y el techo de
 * {@see DateRange}— preguntandoselo al objeto de valor que lo define en lugar de
 * copiar sus limites.
 *
 * ## Rechaza lo desconocido en lugar de ignorarlo
 *
 * Un `?regla=missing_break` mal escrito devolveria las cuatro reglas en silencio y
 * quien lo envio se iria convencido de haber filtrado. Lo hereda del mismo trait.
 *
 * ## `department_id` y `employee_uuid` se validan contra la tabla, no contra el
 * alcance
 *
 * Uno inexistente es un `422` —hay una errata que corregir— y uno existente pero
 * fuera del alcance devuelve `data` vacio, no un `403`: son filtros, no la
 * peticion de un recurso ajeno, y responder `403` convertiria el desplegable de
 * departamentos del panel en un generador de errores. Mismo criterio que
 * `GET /employees` y que el panel de presencia.
 *
 * **`employee_uuid` no se valida contra `employees`**: hacerlo convertiria este
 * endpoint en un oraculo de existencia —un `422` diria «ese identificador no
 * existe» y un `200` vacio diria «existe y no es tuyo»— y eso es justo lo que
 * RS-04 no concede a un responsable. El `uuid` se valida por forma y nada mas.
 */
final class GetComplianceSummaryRequest extends FormRequest
{
    use ValidatesWorkDateRange;

    public function authorize(): bool
    {
        return Gate::allows('view', ComplianceSummary::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->workDateRangeRules(),
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            // `nullable` por los enlaces copiados que arrastran un `?employee_uuid=`
            // vacio de una consulta anterior: eso devuelve la vista entera, no un
            // `422`. Mismo criterio que el `?q=` del panel de presencia.
            'employee_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            // Los casos salen del enum y no de una lista escrita a mano: un valor
            // nuevo entraria aqui solo, y una lista copiada se quedaria atras sin
            // que nada fallara.
            'rule' => [
                'sometimes',
                'nullable',
                'string',
                'in:'.implode(',', array_column(ComplianceRuleName::cases(), 'value')),
            ],
        ];
    }

    public function toCriteria(ScopeGuard $scope): ComplianceSummaryCriteria
    {
        return new ComplianceSummaryCriteria(
            // RF-ID-03: el alcance lo resuelve el servidor a partir del token y
            // entra en la consulta. Va primero porque es la acotacion que quien
            // llama no puede elegir.
            scope: $scope->scopeOf($this->user()),
            from: $this->isoDate('from'),
            to: $this->isoDate('to'),
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            employeeUuid: $this->filledString('employee_uuid'),
            rule: $this->ruleFilter(),
        );
    }

    private function ruleFilter(): ?ComplianceRuleName
    {
        $rule = $this->filledString('rule');

        return $rule === null ? null : ComplianceRuleName::from($rule);
    }

    /**
     * `null` cuando el parametro no viene o viene vacio: `?rule=` es un parametro
     * que el navegador dejo puesto sin valor, no un filtro.
     */
    private function filledString(string $parameter): ?string
    {
        $value = $this->query($parameter);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
