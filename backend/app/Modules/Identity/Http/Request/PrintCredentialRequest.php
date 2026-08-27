<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\PrintCredentialCommand;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/credentials/{uuid}/print` — acuñar el QR y devolver el PDF
 * (RF-QR-04, ADR-034).
 *
 * **Sin ningun campo, y eso es el contrato.** `rules()` esta vacio a proposito y
 * el trait de campos desconocidos hace que cualquier cosa que llegue en el cuerpo
 * se rechace con `422`. No hay `force`, no hay `reprint`, no hay `key_id` y no
 * hay `format`: la reimpresion no existe (ADR-034), la clave la elige el servidor
 * —la vigente, doc 02 §5.3— y este endpoint tiene un solo formato, el de tarjeta
 * de credito. Un campo aceptado «por si acaso» es un campo que alguien acabara
 * enviando convencido de que hace algo.
 *
 * **Es un `POST` aunque devuelva un documento**, porque cambia el estado del
 * sistema de forma irreversible: a partir de aqui esa credencial puede fichar.
 */
final class PrintCredentialRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('print', Credential::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toCommand(string $credentialUuid, ?int $actorUserId): PrintCredentialCommand
    {
        return PrintCredentialCommand::forCredential($credentialUuid, $actorUserId);
    }
}
