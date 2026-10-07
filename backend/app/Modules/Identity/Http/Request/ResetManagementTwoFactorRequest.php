<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\ResetManagementTwoFactorCommand;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/management-accounts/{uuid}/two-factor/reset` (RF-ID-10): motivo
 * obligatorio —retirar el segundo factor de otra persona es la via mas comoda
 * de prepararse el acceso a su cuenta— y reautenticacion de quien lo retira.
 * Sin datos de salud ni juicios de valor en el motivo.
 */
final class ResetManagementTwoFactorRequest extends FormRequest
{
    use ReauthenticatesActor;
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('resetTwoFactor', ManagementAccountView::class);
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

    public function toCommand(string $accountUuid): ResetManagementTwoFactorCommand
    {
        return new ResetManagementTwoFactorCommand(
            accountUuid: $accountUuid,
            reason: $this->string('reason')->trim()->value(),
            actorUuid: $this->actorUuid(),
            proof: $this->actorProof(),
        );
    }
}
