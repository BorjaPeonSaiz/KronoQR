<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\UpdateDepartmentCommand;
use App\Modules\Workforce\Domain\Model\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Cambio de departamento: nombre, responsable o las dos cosas
 * (`UpdateDepartmentRequest` del contrato, `minProperties: 1`).
 *
 * `site_id` no se admite: mover un departamento de centro arrastraria a sus
 * empleados a otra zona horaria (RN-05).
 *
 * ## Autorizacion por campo (RF-ID-10, ADR-051 §2 y §5)
 *
 * Renombrar es de `admin` y `rrhh`. **Enviar `manager_user_uuid` exige ademas
 * el ambito `accounts:*` y la policy `assignManager`** —solo `admin`, nunca un
 * acceso de soporte—. Si falta cualquiera de las dos, `403` y **no cambia
 * nada, tampoco el nombre**: la autorizacion va antes que la validacion y que
 * el caso de uso.
 *
 * El ambito se comprueba aqui y no en la ruta porque la ruta la comparte el
 * renombrado de `rrhh`, que no lo tiene.
 */
final class UpdateDepartmentRequest extends FormRequest
{
    use RejectsUnknownInput;

    /**
     * El ambito de token que exige elegir responsable. Es una copia de
     * `TokenAbility::ACCOUNTS_ALL` de `Identity`, que este modulo no puede
     * importar (Deptrac); `DepartmentManagerAbilityTest` falla si dejan de
     * coincidir.
     */
    public const string ASSIGN_MANAGER_ABILITY = 'accounts:*';

    private const string MANAGER_FIELD = 'manager_user_uuid';

    public function authorize(): bool
    {
        if (! Gate::allows('update', Department::class)) {
            return false;
        }

        if (! $this->has(self::MANAGER_FIELD)) {
            return true;
        }

        return $this->tokenCanAssignManager() && Gate::allows('assignManager', Department::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Sin responsable en el cuerpo, el nombre es obligatorio: es el
            // `minProperties: 1` del contrato.
            'name' => [
                ...($this->has(self::MANAGER_FIELD) ? ['sometimes'] : []),
                'required',
                'string',
                'max:120',
            ],
            self::MANAGER_FIELD => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function toCommand(int $id): UpdateDepartmentCommand
    {
        $managerGiven = $this->has(self::MANAGER_FIELD);
        $manager = $this->input(self::MANAGER_FIELD);

        return new UpdateDepartmentCommand(
            id: $id,
            name: $this->has('name') ? $this->string('name')->trim()->value() : null,
            managerGiven: $managerGiven,
            managerUserUuid: $managerGiven && \is_string($manager) ? strtolower($manager) : null,
        );
    }

    /**
     * Por `method_exists` y no por tipo: quien lleva el rasgo `HasApiTokens` es
     * un modelo de `Identity` —o la concesion de soporte de `Product`—, y este
     * modulo no puede nombrarlo.
     */
    private function tokenCanAssignManager(): bool
    {
        // Ensanchado a proposito: Larastan lo tipa como la cuenta de gestion de
        // `Identity`, pero tambien llega aqui la concesion de soporte de
        // `Product`, y el codigo no debe dar por hecho ninguna de las dos.
        /** @var mixed $actor */
        $actor = $this->user();

        if (! \is_object($actor) || ! method_exists($actor, 'tokenCan')) {
            return false;
        }

        return $actor->tokenCan(self::ASSIGN_MANAGER_ABILITY) === true;
    }
}
