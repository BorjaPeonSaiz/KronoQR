<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Request;

use App\Exceptions\ProblemDetails;
use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Kiosk\Http\Policy\KioskPolicy;
use App\Modules\Kiosk\Http\Support\KioskDevice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `GET /api/v1/kiosk/roster` — un `FormRequest` para un endpoint **sin
 * parametros**.
 *
 * Existe por dos motivos y ninguno es la validacion:
 *
 * 1. **La policy** (regla dura 18). Se invoca por su nombre desde `authorize()`,
 *    porque el `Gate` global no puede autorizar a un dispositivo (ver
 *    {@see KioskPolicy}). Sin este `FormRequest` habria que llamarla desde el
 *    controlador, y entonces «cada endpoint tiene su policy» dependeria de que
 *    nadie olvide una linea en un metodo.
 * 2. **Rechazar lo que no se conoce.** El endpoint no admite ningun parametro, y
 *    en particular **no admite `site_id`**: el centro sale del token (§7.3, ver
 *    {@see KioskDevice}). Ignorar en silencio un
 *    `?site_id=7` dejaria a quien lo envia convencido de haber pedido el padron
 *    de otro centro y de haberlo recibido — cuando lo que recibio fue el suyo.
 *    Es exactamente el modo de fallo que `RejectsUnknownInput` existe para
 *    cerrar, y aqui ademas tiene consecuencias de seguridad.
 */
final class KioskRosterRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return (new KioskPolicy)->readRoster($this->user());
    }

    /**
     * Ningun parametro. La lista vacia no es un descuido: es lo que hace que
     * `RejectsUnknownInput` rechace cualquier cosa que llegue.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * `400` y no `422`, igual que en el resto del camino del quiosco: el `422`
     * significa «tarjeta rechazada» para este cliente, y una peticion mal formada
     * no puede compartir codigo con eso.
     */
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(ProblemDetails::invalidRequest($errors));
    }
}
