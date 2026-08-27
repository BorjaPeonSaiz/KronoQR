<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\IssueCredentialCommand;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/credentials` — emision de credencial (RF-QR-01, RF-QR-03).
 *
 * **El empleado llega por su UUID publico.** Ni su codigo ni su clave interna:
 * el UUID es el unico identificador de persona que viaja por esta API.
 *
 * **`reason` es obligatorio cuando se reemite.** Reemitir revoca la tarjeta
 * anterior, y una revocacion sin motivo es un hueco en la explicacion de por que
 * alguien dejo de poder fichar. La misma regla la exigen el dominio y el CHECK
 * `credentials_chk_revocation_has_reason`: son tres comprobaciones de lo mismo a
 * proposito, y la que de verdad manda es la de la base de datos.
 */
final class StoreCredentialRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('create', Credential::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'string', 'uuid'],
            'reissue' => ['sometimes', 'boolean'],
            'reason' => ['required_if_accepted:reissue', 'string', 'max:'.Credential::MAX_REASON_LENGTH],
        ];
    }

    public function toCommand(?int $actorUserId): IssueCredentialCommand
    {
        $reason = $this->input('reason');

        return new IssueCredentialCommand(
            employeeUuid: $this->string('employee_uuid')->value(),
            reissue: $this->boolean('reissue'),
            reason: \is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
            actorUserId: $actorUserId,
        );
    }
}
