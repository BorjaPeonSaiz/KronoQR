<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\ChangeOwnPasswordCommand;
use App\Modules\Identity\Http\Policy\OwnPasswordPolicy;
use App\Modules\Identity\Http\Rule\ManagementPasswordPolicy;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/auth/password` (RF-ID-10): la actual —sin `minLength` real, la
 * politica se aplica al fijar— y la nueva con la politica unificada de RF-ID-01.
 *
 * La cuenta y el token salen de la sesion: no hay ningun identificador en el
 * cuerpo que manipular.
 */
final class ChangeOwnPasswordRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        // Por su nombre y no por el `Gate`: esta ruta no exige ambito, asi que
        // le llegan tambien el quiosco y la sesion de portal, cuyo `tokenable`
        // no es `Authorizable` y reventaria con un `TypeError` en el
        // `Gate::before` del paquete de permisos. Mismo criterio que
        // `ReportClientErrorsRequest`.
        return (new OwnPasswordPolicy)->change($this->user());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'min:1', 'max:200'],
            'new_password' => ['required', ...ManagementPasswordPolicy::rules()],
        ];
    }

    public function toCommand(): ChangeOwnPasswordCommand
    {
        $user = $this->user();
        $token = $user?->currentAccessToken();

        return new ChangeOwnPasswordCommand(
            accountUuid: $user instanceof User ? $user->uuid : '',
            currentTokenId: $token instanceof PersonalAccessToken ? $token->id : 0,
            currentPassword: $this->string('current_password')->value(),
            newPassword: $this->string('new_password')->value(),
        );
    }
}
