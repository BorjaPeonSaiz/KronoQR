<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Domain\Model\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Alta de centro de trabajo.
 *
 * `timezone` se valida contra la base de datos de husos del sistema y no contra
 * una lista propia: es el dato del que depende RN-05, y una zona inventada
 * produce jornadas atribuidas al dia equivocado sin dar ningun error.
 *
 * El valor por defecto es `Europe/Madrid` porque es el del mercado inicial, y es
 * **configuracion editable**, no una constante: nada especifico de un cliente
 * vive en el codigo (regla dura 13).
 */
final class StoreSiteRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('create', Site::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // `timezone` valida contra la base de datos de husos de PHP. El
            // dominio lo vuelve a comprobar en `SiteTimezone`, y no sobra: esta
            // regla protege el formulario y aquella protege el calculo de RN-05
            // venga por donde venga el dato.
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
        ];
    }

    public function toCommand(): CreateSiteCommand
    {
        return new CreateSiteCommand(
            name: $this->string('name')->trim()->value(),
            timezone: $this->string('timezone', 'Europe/Madrid')->trim()->value(),
        );
    }
}
