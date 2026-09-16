<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Domain\ValueObject\ComplianceFinding;
use App\Modules\Reporting\Domain\ValueObject\ComplianceRuleStatus;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummary;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Serializa el `200` de `GET /api/v1/compliance/summary`: el esquema
 * `ComplianceSummary` del contrato (**RF-PA-06**).
 *
 * ## Aqui se traduce, y solo aqui
 *
 * Los criterios viajan desde la consulta como **claves** porque el dominio no
 * tiene idioma. La traduccion ocurre en el borde, con el idioma de la peticion,
 * igual que en el informe por periodo. **Si falta un texto se lanza** en vez de
 * servir la clave: `criteria.week` impreso en una pantalla de RRHH no parece un
 * error, parece una pantalla rota — y el cliente ingles no tiene forma de saber
 * que eso era una frase.
 *
 * ## Aqui no se calcula nada
 *
 * Ni un minuto, ni un porcentaje, ni una diferencia. Los tres numeros de cada
 * hallazgo llegan hechos (regla dura 7 aplicada a la presentacion) y los recuentos
 * salen de la consulta, con el alcance ya aplicado: contarlos aqui daria otro
 * numero en cuanto hubiera un filtro de por medio.
 *
 * ## Aqui no se convierte a hora local
 *
 * Y es deliberado: lo que sale son **fechas civiles** —jornadas y semanas— que ya
 * estan en la zona del centro con RN-05 aplicada. La unica marca de tiempo real es
 * `meta.generated_at`, que va en UTC, y la zona viaja aparte en `meta.time_zone`
 * para que el cliente la enseñe en lugar de adivinarla (regla dura 3).
 *
 * @property-read ComplianceSummary $resource
 */
final class ComplianceSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(ComplianceSummary $summary)
    {
        parent::__construct($summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => array_map(self::finding(...), $this->resource->findings),
            'meta' => [
                'generated_at' => UtcInstant::of($this->resource->generatedAt),
                'time_zone' => $this->resource->timeZone,
                'from' => $this->resource->range->isoFrom(),
                'to' => $this->resource->range->isoTo(),
                'profile' => [
                    'id' => $this->resource->profile->id,
                    'name' => $this->resource->profile->name,
                    'jurisdiction' => $this->resource->profile->jurisdiction,
                ],
                'week_starts_on' => $this->resource->weekStartsOn,
                'rules' => array_map(self::rule(...), $this->resource->rules),
                'totals' => [
                    'by_rule' => $this->resource->totals->byRule,
                    'employees_affected' => $this->resource->totals->employeesAffected,
                    'employees_evaluated' => $this->resource->totals->employeesEvaluated,
                ],
                'scope' => $this->resource->scopeName(),
                'criteria' => array_map(self::text(...), $this->resource->criteria),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function finding(ComplianceFinding $finding): array
    {
        return [
            'rule' => $finding->rule->value,
            'requirement' => $finding->rule->requirement(),
            'employee' => [
                'uuid' => $finding->employee->uuid,
                'employee_code' => $finding->employee->employeeCode,
                'full_name' => $finding->employee->fullName(),
                // `null` para quien no tiene departamento, que es un estado
                // legitimo: a esa persona solo la ve una cuenta sin restriccion de
                // alcance (RF-ID-03).
                'department' => $finding->employee->departmentId === null ? null : [
                    'id' => $finding->employee->departmentId,
                    'name' => $finding->employee->departmentName ?? '',
                ],
            ],
            'work_date' => $finding->workDate,
            'week' => $finding->week === null ? null : [
                'starts_on' => $finding->week->startsOn,
                'ends_on' => $finding->week->endsOn,
            ],
            'measured_minutes' => $finding->measuredMinutes,
            'threshold_minutes' => $finding->thresholdMinutes,
            'difference_minutes' => $finding->differenceMinutes,
            'shift_entry_uuid' => $finding->shiftEntryUuid,
            'has_open_shift' => $finding->hasOpenShift,
            'incident' => $finding->incident === null ? null : [
                'id' => $finding->incident->id,
                'status' => $finding->incident->status,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rule(ComplianceRuleStatus $status): array
    {
        return [
            'rule' => $status->rule->value,
            'requirement' => $status->requirement(),
            'threshold_minutes' => $status->thresholdMinutes,
            'evaluated' => $status->evaluated,
            'suspension_reason' => $status->suspensionReason?->value,
        ];
    }

    private static function text(string $key): string
    {
        $line = Lang::get('compliance-summary.'.$key);

        if (! \is_string($line) || $line === 'compliance-summary.'.$key) {
            throw new RuntimeException(
                'Falta el texto «compliance-summary.'.$key.'» en lang/'.Lang::getLocale().'.'
            );
        }

        return $line;
    }
}
