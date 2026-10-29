<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Baja de empleado, en el acto o programada (RF-GP-03, RN-14).
 *
 * `terminated_at` es obligatoria y no se deduce de «hoy»: la baja se registra a
 * menudo dias despues de producirse, o semanas antes en un contrato de
 * temporada, y es la fecha desde la que cuenta la retencion de RL-02 y despues
 * de la cual ningun tramo puede caer (RN-14).
 *
 * `apply_now` es «Aplicar ya», opcional y `false` por omision.
 *
 * **Aqui NO se compara ninguna fecha con hoy**, y es a proposito. «Hoy» es la
 * fecha civil **del centro** (`sites.timezone`), y una regla `before_or_equal:today`
 * la evaluaria con la fecha UTC del servidor: a las 00:30 del dia 3 en Canarias
 * diria que el 3 es mañana. Quien decide si la baja es en el acto, programada o
 * imposible es el dominio (`Employee::offboard()`), con «hoy» resuelto por el
 * caso de uso, y responde `422` en `terminated_at` o en `apply_now` igual que
 * esta validacion.
 */
final class OffboardEmployeeRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('offboard', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'terminated_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'string', 'max:200'],
            'apply_now' => ['sometimes', 'boolean'],
        ];
    }

    public function toCommand(string $uuid): OffboardEmployeeCommand
    {
        $reason = $this->input('reason');

        return new OffboardEmployeeCommand(
            uuid: $uuid,
            terminatedAt: $this->string('terminated_at')->value(),
            reason: \is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
            applyNow: $this->boolean('apply_now'),
        );
    }
}
