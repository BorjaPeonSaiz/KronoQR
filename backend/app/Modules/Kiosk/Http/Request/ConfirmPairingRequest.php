<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Application\Command\ConfirmPairingCommand;
use App\Modules\Kiosk\Domain\Model\PairingRequest;
use App\Modules\Kiosk\Domain\ValueObject\DeviceName;
use App\Modules\Kiosk\Domain\ValueObject\PairingCode;
use App\Modules\Kiosk\Http\Policy\KioskPairingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validacion de `POST /api/v1/kiosk/pair/confirm` (contrato
 * `PairingConfirmRequest`).
 *
 * ## La policy va aqui, y el ambito en la ruta
 *
 * Dos controles distintos (doc 02 §7.3, regla dura 18): el middleware `ability`
 * comprueba `settings:*` y {@see KioskPairingPolicy}
 * comprueba que el rol es `admin`. El sujeto de la autorizacion es
 * {@see PairingRequest} —«una solicitud de emparejamiento de esta instalacion»—
 * y no una fila: cuando se autoriza todavia no se sabe cual es, ni si existe.
 *
 * ## `code` se valida por su patron y no se resuelve aqui
 *
 * Un `422` sobre el formato del codigo —«no son seis digitos»— no dice nada que
 * quien lo teclea no vea en su propia pantalla. Lo que **no** puede decir esta
 * capa es si el codigo existe: eso es el rechazo generico del caso de uso, y son
 * dos respuestas distintas del contrato a proposito.
 *
 * ## `name` es obligatorio y aqui solo se comprueba su forma
 *
 * Que el nombre este libre lo decide el caso de uso, dentro de la transaccion:
 * una comprobacion previa en el `FormRequest` seria una condicion de carrera con
 * otra confirmacion simultanea, y ademas duplicaria la regla de la reactivacion
 * por nombre (ADR-028), que no es «esta libre o no» sino «esta libre, o lo tiene
 * un quiosco revocado que se reactiva».
 *
 * ## El actor no se declara, se toma de la sesion
 *
 * Aceptarlo en el cuerpo permitiria dar de alta un quiosco a nombre de otra
 * persona, y el asiento de `audit_log` perderia justo lo que lo hace util.
 */
final class ConfirmPairingRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('confirm', PairingRequest::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[0-9]{'.PairingCode::LENGTH.'}$/'],
            'name' => ['required', 'string', 'min:1', 'max:'.DeviceName::MAX_LENGTH],
        ];
    }

    public function toCommand(): ConfirmPairingCommand
    {
        $actor = $this->user();

        return new ConfirmPairingCommand(
            code: PairingCode::of($this->string('code')->value()),
            name: DeviceName::of($this->string('name')->value()),
            actorUserId: $actor instanceof Model && is_numeric($actor->getKey())
                ? (int) $actor->getKey()
                : null,
        );
    }
}
