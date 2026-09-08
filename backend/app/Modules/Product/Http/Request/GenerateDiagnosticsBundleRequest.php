<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/diagnostics/bundle` (contrato `DiagnosticsBundleRequest`).
 *
 * ## Dos autorizaciones, no una
 *
 * `generate` siempre; `includePersonalData` **ademas**, y solo cuando se pide.
 * Es lo que hace que un token de soporte pueda generar el paquete anonimizado
 * —para eso se le concedio el acceso— y reciba `403` si intenta llevarse la
 * plantilla (RL-19, ADR-020).
 *
 * La segunda comprobacion vive aqui y no en el controlador porque depende del
 * **cuerpo** de la peticion: solo hay que exigirla si `include_personal_data`
 * llego a `true`, y el sitio donde el cuerpo ya esta validado es este.
 *
 * ## Sin cuerpo, paquete anonimizado
 *
 * El contrato declara el cuerpo opcional. Un `POST` vacio es el caso normal —el
 * boton «Generar y descargar» del panel— y tiene que funcionar sin que el
 * cliente mande nada.
 *
 * ## El tope de `period_days` es configuracion, no una constante
 *
 * `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS`, 31 de serie, alineado con
 * el maximo del contrato. Un mes cubre cualquier incidencia de nomina sin
 * convertir el paquete en una exportacion del registro horario por la puerta de
 * atras.
 */
final class GenerateDiagnosticsBundleRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        if (! Gate::allows('generate', DiagnosticsBundle::class)) {
            return false;
        }

        return $this->boolean('include_personal_data') === false
            || Gate::allows('includePersonalData', DiagnosticsBundle::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'include_personal_data' => ['sometimes', 'boolean'],
            'period_days' => ['sometimes', 'integer', 'min:1', 'max:'.$this->maximumPeriodDays()],
        ];
    }

    public function toOptions(): DiagnosticsOptions
    {
        if (! $this->boolean('include_personal_data')) {
            return DiagnosticsOptions::anonymized();
        }

        $period = $this->validated('period_days');

        return DiagnosticsOptions::withPersonalData(
            is_numeric($period) ? (int) $period : DiagnosticsOptions::DEFAULT_PERIOD_DAYS,
        );
    }

    private function maximumPeriodDays(): int
    {
        return max(1, Config::integer('product.diagnostics_personal_data_max_period_days', 31));
    }
}
