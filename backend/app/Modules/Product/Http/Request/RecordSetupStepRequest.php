<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Application\Command\RecordSetupStepCommand;
use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupStepState;
use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `PUT /api/v1/setup/steps/{step}` (contrato `RecordSetupStepRequest`).
 *
 * **`pending` no se admite en el cuerpo.** No se declara que algo esta sin
 * hacer: simplemente no se marca. Aceptarlo daria una forma de **retroceder** un
 * paso ya resuelto, y con ella la de reabrir a medias un asistente que se supone
 * de un solo uso.
 *
 * **El paso viaja en la ruta y no en el cuerpo**, asi que la validacion de que
 * existe la hace {@see SetupStep::fromString()} en el controlador: un paso
 * inventado es un `404` —el recurso direccionado no existe— y no un `422`.
 */
final class RecordSetupStepRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('record', SetupState::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'in:'.SetupStepState::COMPLETED->value.','.SetupStepState::SKIPPED->value],
        ];
    }

    public function toCommand(SetupStep $step): RecordSetupStepCommand
    {
        /** @var string $state */
        $state = $this->validated('state');

        return new RecordSetupStepCommand(
            step: $step,
            state: SetupStepState::from($state),
            actorUuid: $this->actorUuid(),
        );
    }

    /**
     * El UUID publico de quien marca, **nunca su correo ni su nombre** (regla
     * dura 21). Se toma de la sesion y no del cuerpo: aceptarlo del cliente
     * permitiria dejar constancia a nombre de otra persona.
     */
    private function actorUuid(): ?string
    {
        $actor = $this->user();

        return $actor instanceof ManagementActor ? $actor->actorUuid() : null;
    }
}
