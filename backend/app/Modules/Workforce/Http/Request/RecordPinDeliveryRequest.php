<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Workforce\Application\Command\RecordPinDeliveryCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Registro de la entrega del PIN (RF-ID-09).
 *
 * **Tampoco tiene campos.** Quien entrego es quien esta autenticado y cuando fue
 * lo pone el reloj del servidor: los dos datos son el contenido del acuse, y
 * dejar que el cliente los declare lo convertiria en una afirmacion sin valor
 * probatorio. Registrar una entrega de ayer, o a nombre de otra persona, no es
 * una correccion de este endpoint: es una correccion trazada (RN-13).
 *
 * El actor se lee por el puerto {@see ManagementActor} y no por el modelo de
 * `Identity`: `Workforce` no puede importar nada suyo (doc 02 §1.6).
 */
final class RecordPinDeliveryRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('deliverPin', Employee::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $uuid): RecordPinDeliveryCommand
    {
        $actor = $this->user();

        if (! $actor instanceof ManagementActor) {
            // La policy ya ha dejado pasar solo a cuentas de gestion; llegar
            // aqui sin actor seria una incoherencia del guard, no un caso de
            // negocio, y un acuse sin responsable no sirve para nada.
            throw new RuntimeException('La entrega del PIN necesita una cuenta de gestion autenticada.');
        }

        return new RecordPinDeliveryCommand(
            employeeUuid: $uuid,
            deliveredByUserUuid: $actor->actorUuid(),
        );
    }
}
