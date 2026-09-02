<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CreateSiteCommand;
use App\Modules\Workforce\Domain\Model\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/setup/site` (contrato `CreateInstallationSiteRequest`).
 *
 * **`timezone` es obligatorio y no tiene valor por defecto en el servidor**,
 * aunque la columna traiga `Europe/Madrid` de serie. El asistente lo propone
 * relleno, y aun asi tiene que viajar: es el dato del que depende RN-05, y una
 * puesta en marcha que lo omitiera por descuido dejaria un hotel de Canarias
 * calculando sus jornadas con la hora peninsular sin que nadie lo eligiera. Un
 * campo omitible aqui es un turno de noche mal atribuido dentro de seis meses.
 *
 * **La regla `timezone` de Laravel valida contra la base de datos IANA del
 * sistema**, que es la misma con la que despues se hara la conversion: asi no
 * hay dos ideas distintas de que zonas existen.
 *
 * **Sin `compliance_profile_id`.** El centro nace sin perfil asignado, que es
 * como se dice «usa el de la instalacion»: con un centro por instalacion
 * (ADR-040) hay exactamente un perfil vigente, que `GET
 * /api/v1/compliance-profile` resuelve por `is_default` y devuelve con `source`.
 * Un campo para elegirlo seria un parametro que ningun cliente necesita distinto
 * y una segunda forma de decir lo mismo.
 */
final class CreateInstallationSiteRequest extends FormRequest
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
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ];
    }

    public function toCommand(): CreateSiteCommand
    {
        return new CreateSiteCommand(
            name: $this->string('name')->trim()->value(),
            timezone: $this->string('timezone')->trim()->value(),
        );
    }
}
