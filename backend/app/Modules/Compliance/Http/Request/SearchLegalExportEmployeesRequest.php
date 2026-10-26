<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Domain\Exception\InvalidEmployeeSearch;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use App\Modules\Shared\Domain\ValueObject\NationalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * `POST /api/v1/reports/legal-export/employees/search` (RF-IN-05).
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
 * ## Los minimos los mide el dominio, y solo el
 *
 * Las reglas de aqui son las de forma —tipo, presencia y la longitud maxima de
 * lo recibido, que son el `maxLength` del contrato—. Si pasan, {@see self::after()}
 * construye el {@see EmployeeSearch}, que recorta el termino —tambien los
 * espacios Unicode— y normaliza el documento antes de medirlos, y su
 * {@see InvalidEmployeeSearch} se traduce en el `422` del campo. Asi la regla de
 * longitud esta en un solo sitio del backend y el `422` sigue señalando el
 * campo con el mensaje traducido de `validation.min.string`.
 *
 * **Ningun mensaje repite el valor recibido**: el cuerpo es un nombre o un DNI,
 * y un `422` se registra.
 */
final class SearchLegalExportEmployeesRequest extends FormRequest
{
    use RejectsUnknownInput;

    private ?EmployeeSearch $search = null;

    public function authorize(): bool
    {
        return Gate::allows('searchEmployees', LegalExport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => [
                'required_without:national_id',
                'prohibits:national_id',
                'string',
                'max:'.EmployeeSearch::MAX_TEXT_LENGTH,
            ],
            'national_id' => [
                'required_without:q',
                'prohibits:q',
                'string',
                'max:'.NationalId::MAX_LENGTH,
            ],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Con la forma ya rota no hay criterio que construir, y un
                // segundo error sobre el mismo campo solo confundiria.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $this->search = $this->buildSearch();
                } catch (InvalidEmployeeSearch $rejection) {
                    // `validation.min.string` o `validation.max.string`, como
                    // las reglas de Laravel: el mismo texto en los dos idiomas.
                    $message = trans('validation.'.$rejection->bound.'.string', [
                        'attribute' => $validator->getDisplayableAttribute($rejection->field),
                        $rejection->bound => $rejection->limit,
                    ]);

                    $validator->errors()->add(
                        $rejection->field,
                        \is_string($message) ? $message : $rejection->getMessage(),
                    );
                }
            },
        ];
    }

    public function toSearch(): EmployeeSearch
    {
        return $this->search ?? throw new LogicException('El criterio de busqueda no se puede construir antes de la peticion.');
    }

    /**
     * @throws InvalidEmployeeSearch
     */
    private function buildSearch(): EmployeeSearch
    {
        $nationalId = $this->input('national_id');

        if (\is_string($nationalId)) {
            return EmployeeSearch::byNationalId($nationalId);
        }

        $text = $this->input('q');

        return EmployeeSearch::byText(\is_string($text) ? $text : '');
    }
}
