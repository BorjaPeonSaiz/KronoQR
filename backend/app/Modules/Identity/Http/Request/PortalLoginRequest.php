<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\AuthenticatePortalEmployeeCommand;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validacion del acceso al portal (`POST /api/v1/me/login`, RF-ID-06).
 *
 * ## `employee_code` no lleva patron, y es la regla dura 17
 *
 * Solo se comprueba la longitud, exactamente igual que en el fichaje de respaldo
 * por PIN. Un `regex` que describiera la forma del codigo haria que uno
 * malformado devolviera `400` y uno bien formado pero inexistente `401`, y con
 * eso la **validacion** distinguiria lo que el resto del endpoint se cuida de no
 * distinguir: bastaria con mirar el codigo de estado para saber que codigos de
 * empleado existen.
 *
 * ## `pin` si lleva patron, y no contradice lo anterior
 *
 * Seis digitos. La longitud del PIN es publica —el contrato la fija en
 * `IssuedPin.pin`, que es lo que el panel enseña al emitirlo— y **no depende de
 * si el codigo existe ni de si el PIN acierta**: un PIN correcto y uno
 * incorrecto tienen la misma forma. Validarla aqui frena a un cliente mal
 * escrito sin filtrar nada.
 *
 * ## El PIN viaja en claro, y aqui eso es lo correcto
 *
 * No hay campo `pin_sealed`. El sobre de `crypto_box_seal` existe en el quiosco
 * porque alli el PIN puede quedarse horas en IndexedDB antes de salir (RF-AT-11,
 * regla dura 19). Este es un acceso **sincrono**: sin red no hay sesion que
 * abrir, asi que no hay nada que encolar. Un sobre aqui obligaria a distribuir
 * una clave publica al navegador sin proteger de nada que TLS no proteja ya, y
 * la clave privada esta en el mismo servidor que recibe la peticion.
 *
 * ## `400` y no `422` ante un error de forma
 *
 * Por lo mismo que en `/scan/pin`: el codigo que este endpoint reserva para «no
 * entras» es el `401`, y compartir `422` entre «te falta un campo» y «revisa tus
 * credenciales» dejaria al portal sin poder distinguir «corrige el formulario»
 * de «vuelve a intentarlo». Ademas, un `422` con el campo señalado diria **cual**
 * de los dos falla.
 */
final class PortalLoginRequest extends FormRequest
{
    use RejectsUnknownInput;

    /**
     * Endpoint publico: la autorizacion la hace el propio acto de autenticarse.
     * Es la unica ruta del portal sin policy, porque todavia no hay nadie a
     * quien autorizar.
     */
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
            'employee_code' => ['required', 'string', 'min:1', 'max:32'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Habla de la FORMA del PIN, que es publica, y nunca de su
            // contenido: describir un secreto en un cuerpo de error es como se
            // filtra media credencial.
            'pin.regex' => 'El PIN son seis digitos.',
        ];
    }

    public function toCommand(): AuthenticatePortalEmployeeCommand
    {
        return new AuthenticatePortalEmployeeCommand(
            // Se recorta el espacio de alrededor porque un movil lo añade solo al
            // pegar. La caja no se toca: la columna es `CITEXT` y compara sin
            // distinguir mayusculas.
            employeeCode: $this->string('employee_code')->trim()->value(),
            pin: $this->string('pin')->value(),
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }
}
