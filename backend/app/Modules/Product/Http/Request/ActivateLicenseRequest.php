<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/license/activate` (contrato `ActivateLicenseRequest`).
 *
 * ## Un solo campo, y la validacion de verdad no esta aqui
 *
 * Lo unico que se comprueba en esta capa es que llegue una cadena no vacia y
 * dentro de un tamaño razonable. Que la clave sea autentica lo decide la firma
 * ed25519 en `Infrastructure/`, y no se puede comprobar con una regla de
 * Laravel.
 *
 * **No hay ninguna expresion regular sobre el formato.** Seria una segunda
 * fuente de verdad sobre `KQL1.<carga>.<firma>` que se desincronizaria el dia
 * que naciera `KQL2`, y ademas daria un `422` distinto —y peor— que el mensaje
 * que ya sabe dar el verificador: «la clave esta incompleta, vuelve a copiarla»
 * frente a «el campo signed_key tiene un formato invalido».
 *
 * ## El tope de longitud existe, y es generoso a proposito
 *
 * 8 KB. No protege de nada —la clave no es un secreto y el endpoint es de
 * `admin` con `throttle:management`— y esta para que un cuerpo de 40 MB no
 * llegue hasta la verificacion. El peor fallo posible aqui seria una **clave
 * legitima que no se puede activar en el hotel que la compro**, asi que el
 * limite se pone tres ordenes de magnitud por encima de lo que ocupa una clave
 * real (unos 500 bytes).
 *
 * ## El actor no se declara, se toma de la sesion
 *
 * Aceptarlo en el cuerpo permitiria firmar la activacion de una licencia a
 * nombre de otra persona.
 */
final class ActivateLicenseRequest extends FormRequest
{
    /** Tope defensivo del cuerpo, en caracteres. Una clave real ocupa unos 500. */
    private const int MAX_KEY_LENGTH = 8192;

    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('activate', LicenseStatus::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'signed_key' => ['required', 'string', 'min:1', 'max:'.self::MAX_KEY_LENGTH],
        ];
    }

    public function toCommand(): ActivateLicenseCommand
    {
        /** @var string $signedKey */
        $signedKey = $this->validated('signed_key');

        return new ActivateLicenseCommand($signedKey, $this->actorUserId());
    }

    private function actorUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
