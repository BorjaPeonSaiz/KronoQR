<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\ResetManagementPasswordCommand;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/management-accounts/{uuid}/password/reset` (RF-ID-10): motivo
 * obligatorio, que entra en `user.password_reset`, y reautenticacion del
 * `admin` que la restablece.
 */
final class ResetManagementPasswordRequest extends FormRequest
{
    use ReauthenticatesActor;
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('resetPassword', ManagementAccountView::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:190'],
            ...$this->actorProofRules(),
        ];
    }

    public function toCommand(string $accountUuid): ResetManagementPasswordCommand
    {
        return new ResetManagementPasswordCommand(
            accountUuid: $accountUuid,
            reason: $this->string('reason')->trim()->value(),
            actorUuid: $this->actorUuid(),
            proof: $this->actorProof(),
        );
    }
}
