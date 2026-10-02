<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\OffboardEmployeeCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Baja de empleado (RF-GP-03).
 *
 * `terminated_at` es obligatoria y no se deduce de «hoy»: la baja se registra a
 * menudo dias despues de producirse, y es la fecha desde la que cuenta la
 * retencion de RL-02 y desde la que RN-14 deja de admitir fichajes.
 *
 * **Aqui NO se comprueba que no sea posterior a hoy** (RN-14, 2.2.0), y es a
 * proposito. «Hoy» es la fecha civil **del centro** (`sites.timezone`), y una
 * regla `before_or_equal:today` la evaluaria con la fecha UTC del servidor: a
 * las 00:30 del dia 3 en Canarias rechazaria el 3, que ya es hoy. La barrera es
 * el dominio (`Employee::offboard()`), con «hoy» resuelto por el caso de uso con
 * el reloj y la zona del centro, y responde `422` en `terminated_at` igual que
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
        ];
    }

    public function toCommand(string $uuid): OffboardEmployeeCommand
    {
        $reason = $this->input('reason');

        return new OffboardEmployeeCommand(
            uuid: $uuid,
            terminatedAt: $this->string('terminated_at')->value(),
            reason: \is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        );
    }
}
