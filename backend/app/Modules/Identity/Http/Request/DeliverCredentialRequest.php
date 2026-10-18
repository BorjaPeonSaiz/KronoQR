<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\DeliverCredentialCommand;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/credentials/{uuid}/deliver` — registrar la entrega (RF-QR-06).
 *
 * **El responsable NO se acepta en el cuerpo.** Es quien tiene la sesion abierta,
 * y punto. Un campo `delivered_by` permitiria firmar una entrega a nombre de un
 * companero, y este registro existe precisamente para poder decir quien entrego
 * que: si se puede declarar, deja de valer como firma.
 *
 * **La fecha tampoco.** La pone el reloj del servidor. Aceptarla abriria la
 * puerta a antedatar una entrega, que es exactamente el tipo de retoque que la
 * regla dura 5 impide en el resto del registro.
 *
 * Por eso `rules()` esta vacio y el trait rechaza cualquier campo: el cuerpo de
 * esta peticion es `{}`.
 */
final class DeliverCredentialRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('deliver', Credential::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $credentialUuid, int $actorUserId): DeliverCredentialCommand
    {
        return new DeliverCredentialCommand(
            credentialUuid: $credentialUuid,
            // Por la API, quien maneja el sistema y quien responde de la entrega
            // son la misma persona. Por consola tambien desde la 2.3.0: se
            // declara con `--responsible=`, la orden la enseña y se confirma, y
            // el actor del asiento es esa cuenta (`user`).
            deliveredByUserId: $actorUserId,
            actorUserId: $actorUserId,
        );
    }
}
