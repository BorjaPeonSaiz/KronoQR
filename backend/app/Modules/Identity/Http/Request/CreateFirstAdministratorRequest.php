<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\CreateFirstAdministratorCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /api/v1/setup/administrator` (contrato `CreateFirstAdministratorRequest`).
 *
 * ## Aqui SI se aplica la politica de robustez, al contrario que en `/auth/login`
 *
 * Porque este es el sitio donde la contrasena **se fija** (RF-ID-01), y ese es
 * el criterio del producto: se exige al establecerla, nunca al usarla. Es la
 * misma politica que aplica `identity:create-user` desde consola, y esta escrita
 * dos veces a proposito —una por cada camino de alta— porque `Password` es una
 * regla de validacion y las reglas de validacion viven en el borde.
 *
 * **Sin `uncompromised()`**, igual que en la consola: esa regla consulta un
 * servicio externo por HTTP y el producto se instala en servidores sin salida a
 * internet (ADR-016), donde fallaria o —peor— colgaria la creacion del primer
 * administrador.
 *
 * **La longitud minima es configuracion** (regla dura 13,
 * `identity.password.min_length`, 12 de serie) con un suelo de 8 escrito en
 * codigo: una instalacion no puede bajar de ahi por mucho que edite su `.env`.
 *
 * ## Endpoint publico y sin `authorize()`
 *
 * No hay a quien autorizar: se llama cuando la instalacion no tiene ninguna
 * cuenta. La unica guarda —que no exista ninguna— es del caso de uso, que es
 * quien tiene el dato, y no de esta clase.
 *
 * ## `role` no se acepta
 *
 * `RejectsUnknownInput` lo rechaza si alguien lo envia. El primero es siempre
 * `admin`: dejar elegir permitiria crear un `auditor` como primera cuenta y
 * dejar la instalacion sin nadie capaz de configurarla.
 */
final class CreateFirstAdministratorRequest extends FormRequest
{
    /** Suelo de la longitud minima. Ni el `.env` del cliente puede bajar de aqui. */
    private const int PASSWORD_FLOOR = 8;

    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // `unique` no hace falta: no hay ninguna cuenta cuando esto se
            // ejecuta. El `UNIQUE` de `users.email` sigue siendo la garantia si
            // dos pestañas lo intentan a la vez, y el caso de uso ya rechaza la
            // segunda por «ya hay cuentas».
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:200', $this->passwordPolicy()],
            'locale' => ['sometimes', 'string', 'min:2', 'max:10'],
            'device_name' => ['sometimes', 'string', 'max:60'],
        ];
    }

    public function toCommand(): CreateFirstAdministratorCommand
    {
        return new CreateFirstAdministratorCommand(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->trim()->value(),
            password: $this->string('password')->value(),
            locale: $this->string('locale', 'es')->trim()->value(),
            deviceName: $this->string('device_name', 'Panel de gestion')->trim()->value(),
        );
    }

    private function passwordPolicy(): Password
    {
        return Password::min(max(self::PASSWORD_FLOOR, config()->integer('identity.password.min_length')))
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }
}
