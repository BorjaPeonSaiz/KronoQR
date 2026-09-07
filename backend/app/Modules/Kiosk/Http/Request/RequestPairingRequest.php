<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Application\Command\RequestPairingCommand;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validacion de `POST /api/v1/kiosk/pair` (contrato `PairingRequestBody`).
 *
 * ## `authorize()` devuelve `true`, y es la unica ruta de este modulo donde eso
 * es correcto
 *
 * La ruta es **publica** por necesidad: quien la llama todavia no tiene token,
 * porque es justo el que viene a buscar. No hay actor al que autorizar. Lo que la
 * protege no es una policy sino que **no puede hacer nada**: crea una solicitud
 * pendiente que no vincula, no lee y no autoriza, y que solo sirve si un `admin`
 * la confirma. Mas el limitador `pairing-request` por IP (§7.1).
 *
 * No es una excepcion a la regla dura 18 y conviene decirlo: aquella exige policy
 * en cada endpoint **que decida sobre datos**, y aqui no hay ninguno.
 *
 * ## Un solo campo, y todo lo demas se rechaza
 *
 * `RejectsUnknownInput` convierte en `400` cualquier campo de mas. Es una
 * escritura publica: un `site_id` ignorado en silencio dejaria a quien lo envia
 * creyendo que ha elegido centro, y no hay eleccion posible (ADR-040).
 *
 * ## `400` y no `422`
 *
 * Como en el resto del camino del quiosco. En este cliente el `422` significa
 * «rechazado» —tarjeta o emparejamiento— y no puede compartirse con un error de
 * forma (regla dura 17): la tablet reintenta ante uno y vuelve al paso 1 ante el
 * otro.
 */
final class RequestPairingRequest extends FormRequest
{
    use RejectsUnknownInput;

    /** El mismo alfabeto que el latido y que el contrato. */
    private const string APP_VERSION = '/^[0-9A-Za-z][0-9A-Za-z.+-]*$/';

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
            'app_version' => ['required', 'string', 'min:1', 'max:32', 'regex:'.self::APP_VERSION],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }

    public function toCommand(): RequestPairingCommand
    {
        return new RequestPairingCommand($this->string('app_version')->value());
    }
}
