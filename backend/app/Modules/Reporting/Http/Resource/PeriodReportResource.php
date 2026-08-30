<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportedDuration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Serializacion del esquema `PeriodReport` del contrato (RF-IN-01, RF-IN-02,
 * RF-IN-03).
 *
 * ## Cada duracion sale dos veces, y las dos hacen falta
 *
 * `*_minutes` es un entero para quien calcula; `worked`, `contracted`,
 * `deviation` y `overtime` son `HH:MM` para quien lee. **Nunca una hora
 * decimal**: `/informe-nuevo` lo prohibe por escrito y el motivo esta en
 * {@see ReportedDuration}. Los minutos son enteros desde el esquema (RN-06,
 * ADR-007), asi que no hay redondeo por medio entre la proyeccion y esta linea.
 *
 * ## Los criterios se traducen aqui y no antes
 *
 * El dominio los transporta como **claves** porque no tiene idioma. Esta capa es
 * la que sabe en cual esta hablando, y los resuelve contra
 * `lang/{es,en}/reports.php`. Si falta una traduccion se rompe en voz alta en
 * lugar de sacar `criteria.source` en pantalla: un informe cuyos criterios no se
 * entienden es peor que un error, porque se cree.
 *
 * ## El sujeto tiene una sola forma para los tres agrupamientos
 *
 * Un unico esquema con campos que pueden ser nulos, en lugar de tres variantes.
 * El cliente pinta la misma tabla en los tres casos y `kind` le dice que columnas
 * tienen sentido; tres esquemas habrian obligado a tres tablas para la misma
 * pantalla.
 *
 * `label` es lo que se enseña: el nombre de la persona, el del departamento o el
 * del centro. Va **nulo** para el cubo de quien no tiene departamento —no se
 * inventa un «Sin departamento» en castellano desde el servidor— y lo traduce el
 * cliente, que es quien sabe en que idioma esta pintando.
 *
 * @property-read PeriodReport $resource
 */
final class PeriodReportResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PeriodReport $report */
        $report = $this->resource;

        return [
            'from' => $report->range->isoFrom(),
            'to' => $report->range->isoTo(),
            'granularity' => $report->granularity->value,
            'group_by' => $report->grouping->value,
            'data' => array_map(
                static fn (PeriodReportRow $row): array => self::row($row),
                $report->rows,
            ),
            'meta' => [
                // La zona del centro (ADR-040). Viaja en la respuesta para que el
                // cliente no la adivine ni use la del navegador (regla dura 3):
                // los `work_date` ya estan expresados en ella.
                'time_zone' => $report->timeZone,
                'generated_at' => $report->generatedAt->format('Y-m-d\TH:i:s.u\Z'),
                'row_count' => $report->rowCount(),
                'criteria' => self::criteria($report),
                'contract_coverage' => [
                    'days_without_contract' => $report->contractCoverage->daysWithoutContract,
                    'employees_without_contract' => $report->contractCoverage->employeesWithoutContract,
                    'complete' => $report->contractCoverage->isComplete(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(PeriodReportRow $row): array
    {
        return [
            'period' => [
                'from' => $row->isoPeriodStart(),
                'to' => $row->isoPeriodEnd(),
            ],
            'subject' => [
                'kind' => $row->subject->kind->value,
                'employee_uuid' => $row->subject->employeeUuid,
                'employee_code' => $row->subject->employeeCode,
                'full_name' => $row->subject->fullName,
                'department_id' => $row->subject->departmentId,
                'label' => $row->subject->fullName ?? $row->subject->label,
            ],
            'worked_minutes' => $row->workedMinutes,
            'worked' => ReportedDuration::ofMinutes($row->workedMinutes)->toClockText(),
            'shift_count' => $row->shiftCount,
            'days_in_period' => $row->daysInPeriod,
            'days_with_activity' => $row->daysWithActivity,
            'days_without_activity' => $row->daysWithoutActivity(),
            'open_shift_days' => $row->openShiftDays,
            'incident_days' => $row->incidentDays,
            'contracted_minutes' => $row->contractedMinutes,
            'contracted' => ReportedDuration::ofMinutes($row->contractedMinutes)->toClockText(),
            'deviation_minutes' => $row->deviationMinutes(),
            'deviation' => ReportedDuration::ofMinutes($row->deviationMinutes())->toClockText(),
            'overtime_minutes' => $row->overtimeMinutes(),
            'overtime' => ReportedDuration::ofMinutes($row->overtimeMinutes())->toClockText(),
            'days_without_contract' => $row->daysWithoutContract,
        ];
    }

    /**
     * @return list<string>
     */
    private static function criteria(PeriodReport $report): array
    {
        return array_map(static fn (string $key): string => self::text($key), $report->criteria);
    }

    /**
     * Un texto de `reports.php` del idioma en curso.
     *
     * Mismo criterio que en la exportacion legal: `Lang::get` devuelve la clave
     * cuando no hay traduccion, y una clave suelta en la lista de criterios de un
     * informe de horas es peor que un fallo, porque nadie la lee como un fallo.
     */
    private static function text(string $key): string
    {
        $line = Lang::get('reports.'.$key);

        if (! \is_string($line) || $line === 'reports.'.$key) {
            throw new RuntimeException('Falta el texto «reports.'.$key.'» en lang/'.Lang::getLocale().'.');
        }

        return $line;
    }
}
