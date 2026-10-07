<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\CreateManagementAccountCommand;
use App\Modules\Identity\Application\Query\ManagementAccountView;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/management-accounts` (RF-ID-10): alta **sin contrasena** —la
 * genera el servidor— y **sin departamentos** —el responsable se asigna en el
 * departamento—, con la reautenticacion del `admin` que la crea.
 */
final class StoreManagementAccountRequest extends FormRequest
{
    use ReauthenticatesActor;
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('create', ManagementAccountView::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'role' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (UserRole $role): string => $role->value,
                UserRole::managementRoles(),
            ))],
            // Uno de los idiomas activos en la instalacion (regla dura 13).
            'locale' => ['sometimes', 'string', 'in:'.implode(',', $this->availableLocales())],
            ...$this->actorProofRules(),
        ];
    }

    public function toCommand(): CreateManagementAccountCommand
    {
        return new CreateManagementAccountCommand(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->trim()->value(),
            role: UserRole::from($this->string('role')->value()),
            locale: $this->has('locale')
                ? $this->string('locale')->value()
                : resolve(LocalePolicyProvider::class)->current()->default,
            actorUuid: $this->actorUuid(),
            proof: $this->actorProof(),
        );
    }

    /**
     * @return list<string>
     */
    private function availableLocales(): array
    {
        return resolve(LocalePolicyProvider::class)->current()->available;
    }
}
