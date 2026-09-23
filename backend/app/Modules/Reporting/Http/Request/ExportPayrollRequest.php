<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\NormalisesBooleanQuery;
use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/reports/payroll-export` — que periodo se exporta a nomina y en
 * que fichero (**RF-IN-07**).
 *
 * ## Por que no reutiliza {@see DescribesPeriodReport}
 *
 * Porque **dos de sus parametros no existen aqui**, y no por descuido:
 *
 * - **`group_by` es siempre `employee`.** Una nomina se paga a personas. Un
 *   fichero agregado por departamento no tiene ningun uso en un programa de
 *   nomina y, ofrecido como opcion, seria una forma silenciosa de generar un
 *   fichero que no se puede importar. Se fija en el servidor, no se pide.
 * - **`granularity` admite tres de las cuatro**, sin `week`: los periodos de
 *   nomina son el mes o un rango libre, nunca la semana ISO, y `week` produciria
 *   filas a caballo de dos meses que ningun importador sabe repartir. `range` por
 *   omision, que es la pregunta normal —«lo de este mes, en una fila por
 *   persona»—, al contrario que en el informe por periodo, donde `day` es el
 *   grano de la fuente.
 *
 * Con el rasgo compartido, añadir un parametro alla lo habria añadido aqui
 * tambien, y ahi es donde aparece un fichero de nomina agrupado por centro.
 *
 * ## `format` es obligatorio y solo admite dos
 *
 * `csv` y `xlsx`. No hay `pdf`: un PDF no se importa en ninguna nomina, y
 * ofrecerlo arrancaria un Chromium para producir un fichero que nadie puede usar.
 * Tampoco `json`: esa forma la sirve `GET /api/v1/reports/period`.
 *
 * **Sin valor por omision**, por lo mismo que en la descarga del informe: quien
 * pulsa un boton de descarga ya ha elegido formato, y suponer CSV seria decidir
 * por el.
 *
 * ## El alcance no se elige aqui
 *
 * Lo resuelve `ScopeGuard` a partir del token y entra **dentro** de la consulta
 * (RF-ID-03). No hay ningun parametro con el que ampliarlo.
 *
 * ## La policy se declara aqui
 *
 * `PayrollExportPolicy` sobre {@see PayrollLayout}, y el ambito `reports:*` lo
 * verifica el middleware antes (regla dura 18).
 */
final class ExportPayrollRequest extends FormRequest
{
    use NormalisesBooleanQuery;
    use ValidatesWorkDateRange;

    /**
     * Los formatos de fichero de nomina. Una sola lista: la que valida es la que
     * traduce, asi que no pueden divergir.
     *
     * @var list<ReportDelivery>
     */
    private const array FORMATS = [ReportDelivery::Csv, ReportDelivery::Xlsx];

    /**
     * Las granularidades con sentido en nomina. Ver el docblock de la clase.
     *
     * @var list<ReportGranularity>
     */
    private const array GRANULARITIES = [
        ReportGranularity::Range,
        ReportGranularity::Month,
        ReportGranularity::Day,
    ];

    public function authorize(): bool
    {
        return Gate::allows('export', PayrollLayout::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d'],
            'format' => ['required', 'string', 'in:'.implode(',', self::names(self::FORMATS))],
            'granularity' => ['sometimes', 'string', 'in:'.implode(',', self::names(self::GRANULARITIES))],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            'employee_uuid' => ['sometimes', 'uuid', 'exists:employees,uuid'],
            'include_open_shifts' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * La consulta, **siempre por empleado**.
     */
    public function toQuery(ScopeGuard $scope): PeriodReportQuery
    {
        return new PeriodReportQuery(
            scope: $scope->scopeOf($this->user()),
            range: DateRange::between(
                (string) $this->isoDate('from'),
                (string) $this->isoDate('to'),
            ),
            granularity: $this->granularity(),
            // Fijo, no pedido. Ver el docblock de la clase.
            grouping: ReportGrouping::Employee,
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            employeeUuid: $this->isoEmployeeUuid(),
            includeOpenShifts: $this->boolean('include_open_shifts'),
        );
    }

    /**
     * El formato pedido, ya validado.
     *
     * `exportFormat()` y no `format()` por lo mismo que en la descarga del
     * informe: `Illuminate\Http\Request` ya tiene un `format()` con otra firma y
     * otro significado, y sobrescribirlo es la clase de colision que se descubre
     * en produccion.
     */
    public function exportFormat(): ReportDelivery
    {
        return ReportDelivery::from($this->string('format')->value());
    }

    /**
     * `range` por omision: una fila por persona con el periodo entero, que es lo
     * que un programa de nomina espera recibir.
     */
    private function granularity(): ReportGranularity
    {
        $value = $this->string('granularity')->value();

        return $value === '' ? ReportGranularity::Range : ReportGranularity::from($value);
    }

    private function isoEmployeeUuid(): ?string
    {
        $value = $this->query('employee_uuid');

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<ReportDelivery>|list<ReportGranularity>  $cases
     * @return list<string>
     */
    private static function names(array $cases): array
    {
        return array_map(
            static fn (ReportDelivery|ReportGranularity $case): string => $case->value,
            $cases,
        );
    }
}
