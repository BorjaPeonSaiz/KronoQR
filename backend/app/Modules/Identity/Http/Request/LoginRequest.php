<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\AuthenticateUserCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validacion del acceso al panel.
 *
 * **Valida forma, no identidad.** Que el correo tenga forma de correo no dice
 * nada sobre si la cuenta existe, y esta clase no puede empezar a decirlo: la
 * comprobacion vive en el caso de uso y su respuesta es siempre la misma
 * (`401`). La validacion no es autorizacion, y aqui tampoco es autenticacion.
 *
 * `prepareForValidation` no normaliza el correo a minusculas y no hace falta:
 * la columna es `citext` y la comparacion ya es insensible a mayusculas. Lo que
 * si se normaliza es la clave del contador de intentos, para que
 * `Ana@hotel.example` y `ana@hotel.example` no tengan dos cupos de fallos.
 */
final class LoginRequest extends FormRequest
{
    use RejectsUnknownInput;

    /**
     * Endpoint publico: la autorizacion la hace el propio acto de autenticarse.
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
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            // Sin `Password::defaults()`: la politica de robustez se aplica al
            // FIJAR la contrasena, no al usarla. Exigirla aqui haria que una
            // contrasena antigua y corta devolviera un error de validacion
            // distinto del 401, y con eso el endpoint diria si la cuenta existe.
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['sometimes', 'string', 'max:60'],
        ];
    }

    public function toCommand(): AuthenticateUserCommand
    {
        $email = $this->string('email')->trim()->value();

        return new AuthenticateUserCommand(
            email: $email,
            password: $this->string('password')->value(),
            deviceName: $this->string('device_name', 'Panel de gestion')->trim()->value(),
            throttleKey: Str::lower($email).'|'.(string) $this->ip(),
        );
    }
}
