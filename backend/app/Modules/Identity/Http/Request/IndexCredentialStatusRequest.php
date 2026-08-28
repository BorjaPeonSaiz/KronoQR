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
     * Normaliza `pending` antes de validarlo.
     *
     * **Por que hace falta.** El contrato declara `pending` como
     * `schema: {type: boolean}` en la cadena de consulta, y la serializacion
     * estandar de OpenAPI para eso es el literal `pending=true`. La regla
     * `boolean` de Laravel acepta `true`, `false`, `1`, `0`, `"1"` y `"0"`, pero
     * **no** las cadenas `"true"` ni `"false"`, asi que el cliente generado del
     * contrato recibia un `422` por enviar exactamente lo que el contrato pide.
     *
     * Es el unico booleano de consulta de la API: `reissue` de
     * `POST /credentials` viaja en un cuerpo JSON, donde llega ya como booleano
     * de verdad. Por eso esto vive aqui y no en un trait de `Shared`: un trait
     * con un solo usuario es una abstraccion adivinada.
     *
     * **Lo que no hace: tragarse la basura.** `FILTER_NULL_ON_FAILURE` devuelve
     * `null` ante cualquier cosa que no sea un booleano reconocible, y entonces
     * el valor original se deja intacto para que la regla `boolean` siga
     * respondiendo `422`. Un filtro mal escrito tiene que doler, no colarse como
     * `false` y devolver la lista entera en silencio.
     */
    protected function prepareForValidation(): void
    {
        $pending = $this->input('pending');

        if (! \is_string($pending)) {
            return;
        }

        $normalised = filter_var($pending, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($normalised === null) {
            return;
        }

        $this->merge(['pending' => $normalised]);
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
