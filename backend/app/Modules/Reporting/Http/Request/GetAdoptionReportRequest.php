<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Http\Policy\AdoptionReportPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/reports/adoption` — que periodo mide el cuadro (**RF-IN-08**).
 *
 * Dos parametros opcionales y nada mas: aqui no hay alcance, ni departamento, ni
 * empleado. El cuadro es de la **instalacion entera** por privacidad y no por
 * comodidad (regla dura 21), asi que no hay nada que filtrar — y por eso tampoco
 * hay `ScopeGuard` en la firma de `toCriteria()`, al contrario que en sus
 * hermanas de este directorio.
 *
 * La autorizacion son dos comprobaciones y esta es la segunda: el ambito
 * `reports:*` lo verifica el middleware antes y el rol lo decide
 * {@see AdoptionReportPolicy}, `{admin, rrhh}`
 * (regla dura 18).
 */
final class GetAdoptionReportRequest extends FormRequest
{
    use DescribesAdoptionPeriod;

    public function authorize(): bool
    {
        return Gate::allows('view', AdoptionReport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->adoptionPeriodRules();
    }
}
