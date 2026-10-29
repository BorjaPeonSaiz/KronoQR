<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\CancelScheduledOffboardingCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Anulacion de una baja programada (RN-14, RF-GP-03). **Sin cuerpo**: la fecha
 * que se anula es la de la ficha, leida con el candado. Un campo cualquiera
 * responde `422` por `RejectsUnknownInput`, como el resto de operaciones sin
 * campos.
 */
final class CancelScheduledOffboardingRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('cancelOffboarding', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $uuid): CancelScheduledOffboardingCommand
    {
        return new CancelScheduledOffboardingCommand($uuid);
    }
}
