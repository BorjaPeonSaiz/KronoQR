<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use App\Modules\Shared\Domain\ValueObject\NationalId;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/reports/legal-export/employees/search` (RF-IN-05, V21-3-PA-1).
 *
 * ## La autorizacion va antes que la validacion, y no es un detalle
 *
 * `authorize()` corre antes que `rules()`. Un rol sin permiso recibe el mismo
 * `403` exista o no el DNI que envia, y sin que se haya consultado nada: si la
 * validacion fuera primero, un cuerpo mal formado le responderia `422` y uno
 * bien formado `403`, y esa diferencia ya seria informacion para quien no
 * deberia tener ninguna.
 *
 * ## Uno de los dos campos, nunca los dos ni ninguno
 *
 * El `oneOf` del contrato: `required_without` + `prohibits` en los dos sentidos,
 * y {@see RejectsUnknownInput} para cualquier otro campo. Los tres casos son
 * `422`.
 *
 * ## Los minimos se comprueban sobre el valor que se va a usar
 *
 * El termino se recorta antes de contar —tres espacios no son tres caracteres—
 * y el documento se normaliza antes de medir, con {@see NationalId}, igual que
 * lo hara la busqueda. `EmployeeSearch` vuelve a comprobarlo en el dominio; aqui
 * se hace para que el `422` señale el campo.
 *
 * **Ningun mensaje repite el valor recibido**: el cuerpo es un nombre o un DNI,
 * y un `422` se registra.
 */
final class SearchLegalExportEmployeesRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('searchEmployees', LegalExport::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => [
                'required_without:national_id',
                'prohibits:national_id',
                'string',
                'max:'.EmployeeSearch::MAX_TEXT_LENGTH,
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (\is_string($value) && mb_strlen(trim($value)) < EmployeeSearch::MIN_TEXT_LENGTH) {
                        $fail('validation.min.string')->translate(['min' => EmployeeSearch::MIN_TEXT_LENGTH]);
                    }
                },
            ],
            'national_id' => [
                'required_without:q',
                'prohibits:q',
                'string',
                'max:32',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! \is_string($value)) {
                        return;
                    }

                    $normalised = NationalId::fromInput($value);

                    if (! $normalised instanceof NationalId || $normalised->isTooShort()) {
                        $fail('validation.min.string')->translate(['min' => NationalId::MIN_LENGTH]);
                    }
                },
            ],
        ];
    }

    public function toSearch(): EmployeeSearch
    {
        $nationalId = $this->input('national_id');

        if (\is_string($nationalId) && $nationalId !== '') {
            return EmployeeSearch::byNationalId($nationalId);
        }

        $text = $this->input('q');

        return EmployeeSearch::byText(\is_string($text) ? $text : '');
    }
}
