<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Los contratos de una persona (**RF-GP-02**).
 *
 * **Sin filtros y sin paginacion, a proposito.** Una persona tiene unos pocos
 * contratos a lo largo de su vida laboral en el hotel, y la serie solo se
 * entiende entera: paginarla partiria la cadena de vigencias justo donde se ve
 * si tiene huecos. El techo real lo pone la restriccion de exclusion, que impide
 * que dos se solapen.
 *
 * Existe como `FormRequest` y no como una `Gate::authorize()` en el controlador
 * para que la autorizacion se declare en el mismo sitio que en el resto de los
 * endpoints del modulo y para que `RejectsUnknownInput` rechace un
 * `?per_page=50` que nadie va a atender.
 */
final class IndexEmploymentContractRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', EmploymentContract::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
