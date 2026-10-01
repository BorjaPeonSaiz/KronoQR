<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Modules\Identity\Http\Policy\PortalSessionPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/me/logout` — cierre de la sesion del portal (RF-ID-05, PO1).
 *
 * **Solo autoriza.** No hay nada que validar: la sesion que se cierra es la del
 * token que trae la peticion, nunca una que se indique en el cuerpo.
 *
 * **Y no rechaza campos desconocidos**, al contrario que el resto de peticiones
 * del producto: un cierre de sesion que fallara con `422` porque el cliente
 * mando un cuerpo de mas dejaria el token vivo en el ordenador compartido, que
 * es justo lo que esta ruta existe para evitar.
 */
final class PortalLogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (new PortalSessionPolicy)->logOut($this->user());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
