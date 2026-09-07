<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Autorizacion de `GET /api/v1/devices` (**RF-PD-06**, RF-PA-07).
 *
 * ## Un `FormRequest` sin reglas, y con motivo
 *
 * El endpoint no acepta ningun parametro: ni paginacion —una instalacion es un
 * hotel con unos pocos quioscos (ADR-040)— ni filtros ni `site_id`. Lo que este
 * objeto aporta es **la policy**, declarada donde se declara en el resto de los
 * endpoints de gestion del producto, para que la matriz de autorizacion negativa
 * la encuentre en el mismo sitio en todos (regla dura 18).
 *
 * `RejectsUnknownInput` sigue teniendo trabajo: con `rules()` vacio, cualquier
 * campo enviado es desconocido y se rechaza en lugar de ignorarse en silencio.
 */
final class ListDevicesRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', DeviceSummary::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
