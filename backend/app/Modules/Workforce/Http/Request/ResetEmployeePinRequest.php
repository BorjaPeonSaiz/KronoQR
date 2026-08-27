<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\ResetEmployeePinCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Restablecimiento del PIN (RF-ID-09).
 *
 * **Sin ningun campo, y eso es el punto.** El PIN lo genera el servidor y el
 * momento lo pone el reloj: no hay nada que el cliente pueda decidir. El
 * `FormRequest` existe por la otra mitad de su trabajo —autorizar (regla dura
 * 18)— y por rechazar lo que no conoce: quien enviara `{"pin": "123456"}` se
 * iria convencido de haber fijado un PIN que el servidor ignoro.
 */
final class ResetEmployeePinRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('resetPin', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $uuid): ResetEmployeePinCommand
    {
        return new ResetEmployeePinCommand($uuid);
    }
}
