<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\RevokeCredentialCommand;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/credentials/{uuid}/revoke` — revocacion (RF-QR-03).
 *
 * **Es un POST con nombre propio y no un DELETE.** No hay ningun `DELETE` en
 * esta API (regla dura 5): la credencial no se borra, se marca revocada y
 * conserva su historia. Un verbo de borrado invitaria justo a lo contrario.
 *
 * `reason` no admite cadena vacia: `required` mas `min:1` sobre el texto ya
 * recortado. Un motivo en blanco pasa la base de datos —hay texto— y no explica
 * nada.
 */
final class RevokeCredentialRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('revoke', Credential::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:'.Credential::MAX_REASON_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (\is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    public function toCommand(string $credentialUuid, ?int $actorUserId): RevokeCredentialCommand
    {
        return new RevokeCredentialCommand(
            credentialUuid: $credentialUuid,
            reason: $this->string('reason')->value(),
            actorUserId: $actorUserId,
        );
    }
}
