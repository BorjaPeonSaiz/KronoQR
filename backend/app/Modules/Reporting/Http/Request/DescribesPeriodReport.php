<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Http\Requests\NormalisesBooleanQuery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportQuery;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Shared\Application\Authorization\ScopeGuard;

/**
 * Que informe por periodo se pide, escrito una sola vez (**RF-IN-01**,
 * RF-IN-04).
 *
 * ## Por que existe
 *
 * Dos peticiones piden el **mismo** informe: la consulta del panel
 * (`GET /api/v1/reports/period`) y su descarga como fichero
 * (`GET /api/v1/reports/period/export`). La segunda solo añade `format`.
 *
 * Con dos listas de reglas separadas, bastaria añadir un filtro en una para que
 * el fichero que alguien adjunta a un correo describiera un informe distinto del
 * que estaba mirando en pantalla — y el que se creeria seria el equivocado.
 * Es el mismo razonamiento, y el mismo remedio, que {@see ValidatesWorkDateRange}
 * aplico al rango de fechas: no es una abstraccion nueva, son los mismos metodos
 * en un sitio.
 *
 * ## El alcance no se elige aqui
 *
 * Lo resuelve `ScopeGuard` a partir del token y entra **dentro** de la consulta
 * (RF-ID-03). No hay ningun parametro con el que ampliarlo, en ninguna de las
 * dos peticiones.
 *
 * ## `include_open_shifts` llega como texto
 *
 * El contrato lo declara `type: boolean` en la cadena de consulta y el panel lo
 * serializa como `include_open_shifts=true`, que la regla `boolean` de Laravel
 * no acepta. {@see NormalisesBooleanQuery} lo convierte antes de validar, para
 * las dos peticiones a la vez.
 */
trait DescribesPeriodReport
{
    use NormalisesBooleanQuery;
    use ValidatesWorkDateRange;

    /**
     * Las reglas comunes, para que cada peticion las componga con las suyas.
     *
     * @return array<string, list<string>>
     */
    protected function periodReportRules(): array
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
