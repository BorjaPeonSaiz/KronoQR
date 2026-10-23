<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Application\Command\ClaimPairingCommand;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Config;

/**
 * Validacion de `POST /api/v1/kiosk/pair/claim` (contrato `PairingClaimRequest`).
 *
 * ## Publica, y por el mismo motivo que `/kiosk/pair`
 *
 * Quien sondea todavia no tiene token. Lo que la protege es el secreto de
 * recogida, la caducidad y el limitador `pairing-claim` por `pairing_id`.
 *
 * ## LO QUE AQUI NO SE VALIDA, Y ES DELIBERADO
 *
 * `pairing_id` se comprueba como **UUID cualquiera**, no como UUID v7, y el
 * secreto solo por longitud. Una validacion mas estrecha convertiria «formato
 * raro» en un `400` distinguible del `422` de rechazo, que es la misma fuga que
 * `qr_payload` se cuida de no cometer al no declarar `pattern` en el contrato:
 * quien sondea sabria que sus intentos ni siquiera llegaron a compararse.
 *
 * Lo que si se valida es que los dos campos esten y sean cadenas, porque sin eso
 * no hay peticion que procesar.
 */
final class ClaimPairingRequest extends FormRequest
{
    public const int SECRET_MIN = 32;

    public const int SECRET_MAX = 64;

    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'pairing_id' => ['required', 'string', 'uuid'],
            'pairing_secret' => ['required', 'string', 'min:'.self::SECRET_MIN, 'max:'.self::SECRET_MAX],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }

    public function toCommand(): ClaimPairingCommand
    {
        return new ClaimPairingCommand(
            pairingId: $this->string('pairing_id')->value(),
            secret: $this->string('pairing_secret')->value(),
            origin: $this->originFingerprint(),
        );
    }

    /**
     * Huella opaca del origen de la peticion, para la recogida repetida
     * (V6-KI-02): un HMAC-SHA256 de la direccion del cliente —la que deja
     * `TrustProxies` tras el borde— con la clave de la aplicacion. **Nunca la
     * direccion**: lo que se guarda en `device_pairing_requests` no permite
     * recuperarla, ni siquiera probando las cuatro mil millones de IPv4, sin la
     * clave. Nula si no hay direccion o no hay clave: entonces no se puede
     * repetir la recogida, que es fallar hacia el comportamiento de antes.
     */
    private function originFingerprint(): ?string
    {
        $ip = $this->ip();
        $key = Config::string('app.key', '');

        if ($ip === null || $ip === '' || $key === '') {
            return null;
        }

        return hash_hmac('sha256', 'pairing-origin|'.$ip, $key);
    }
}
