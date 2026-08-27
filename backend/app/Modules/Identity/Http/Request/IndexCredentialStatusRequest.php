<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Modules\Identity\Application\Query\CredentialStatusQuery;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/credentials/status` — el panel de RF-QR-08.
 *
 * **Sin `RejectsUnknownInput`.** Es el unico `FormRequest` de credenciales que no
 * lo usa, y a proposito: en una peticion `GET` los parametros desconocidos llegan
 * por la cadena de consulta, donde los añaden proxies, analiticas y el propio
 * navegador (`utm_*`, `_`, `fbclid`). Rechazarlos convertiria un enlace copiado y
 * pegado en un `422`. Lo que si se valida es que los que se reconocen tengan la
 * forma correcta.
 */
final class IndexCredentialStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewStatus', Credential::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'min:1', 'exists:sites,id'],
            'pending' => ['sometimes', 'boolean'],
        ];
    }

    public function toQuery(): CredentialStatusQuery
    {
        $siteId = $this->input('site_id');

        return new CredentialStatusQuery(
            siteId: \is_numeric($siteId) ? (int) $siteId : null,
            pendingOnly: $this->boolean('pending'),
        );
    }
}
