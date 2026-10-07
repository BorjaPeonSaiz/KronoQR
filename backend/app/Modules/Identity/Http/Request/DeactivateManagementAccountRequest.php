<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\DeactivateManagementAccountCommand;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/management-accounts/{uuid}/deactivate` (RF-ID-10): el motivo es
 * obligatorio y sin valor por defecto; es lo que explica meses despues por que
 * una persona dejo de tener acceso.
 */
final class DeactivateManagementAccountRequest extends FormRequest
{
    use ReauthenticatesActor;
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('deactivate', ManagementAccountView::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:190'],
        ];
    }

    public function toCommand(string $accountUuid): DeactivateManagementAccountCommand
    {
        return new DeactivateManagementAccountCommand(
            accountUuid: $accountUuid,
            reason: $this->string('reason')->trim()->value(),
            actorUuid: $this->actorUuid(),
        );
    }
}
