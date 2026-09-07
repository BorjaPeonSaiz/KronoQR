<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Application\Command\UnpairDeviceCommand;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Autorizacion de `POST /api/v1/devices/{uuid}/unpair` (**RF-PD-06**).
 *
 * ## La operacion que puede dejar un hotel sin quiosco
 *
 * Por eso su policy es la mas estrecha del modulo: `admin` y nadie mas
 * (§7.3 nota 5). El middleware comprueba `settings:*` y la policy el rol.
 *
 * ## Sin cuerpo, y por eso `RejectsUnknownInput` importa
 *
 * No hay motivo que declarar: solo hay uno, `unpaired`. Un motivo libre acabaria
 * siendo texto tecleado que nadie normaliza, y la revocacion por tablet robada
 * tiene su propia via en consola con su propio motivo. Cualquier campo enviado se
 * rechaza en lugar de ignorarse.
 *
 * ## El `uuid` no se valida aqui
 *
 * Va en la ruta y la ruta ya lo acota con `whereUuid`. Un `uuid` que no exista es
 * un `404` del caso de uso, y llega **despues** de la autorizacion: si alguna vez
 * respondiera `404` en lugar de `403` a un rol no autorizado, eso ya seria el
 * fallo — enumerar lo que existe pero no se puede ver es una fuga con otro nombre.
 */
final class UnpairDeviceRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('unpair', DeviceSummary::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $deviceUuid): UnpairDeviceCommand
    {
        $actor = $this->user();

        return new UnpairDeviceCommand(
            deviceUuid: $deviceUuid,
            actorUserId: $actor instanceof Model && is_numeric($actor->getKey())
                ? (int) $actor->getKey()
                : null,
        );
    }
}
