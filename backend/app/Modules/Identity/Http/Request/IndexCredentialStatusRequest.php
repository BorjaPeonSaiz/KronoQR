<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Query\CredentialStatusQuery;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/credentials/status` (RF-QR-08).
 *
 * Sin `site_id` (ADR-040): el tablero es el de la instalacion, y `employee_uuid`
 * lo acota a una persona. `RejectsUnknownInput` hace que un `site_id` heredado
 * de un enlace antiguo falle en voz alta en vez de devolver el tablero entero
 * como si hubiera acotado algo.
 */
final class IndexCredentialStatusRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('viewStatus', Credential::class);
    }

    /**
     * El contrato declara `pending` como booleano en la cadena de consulta y la
     * serializacion estandar es `pending=true`; la regla `boolean` de Laravel
     * no acepta esa cadena, asi que se normaliza antes de validar.
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
            'pending' => ['sometimes', 'boolean'],
            // Solo la forma, **sin `exists`**: un UUID que no corresponde a
            // nadie no es un error del cliente sino una consulta que no
            // encuentra a nadie, y el contrato lo resuelve con `200` y
            // `data: []`. Comprobarlo contra la tabla ademas convertiria este
            // parametro en un oraculo de existencia de empleados para
            // cualquiera que pueda leer el panel.
            'employee_uuid' => ['sometimes', 'uuid'],
            // Solo la forma —dos caracteres alfanumericos, la del §5.1—, por lo
            // mismo que con `employee_uuid`: una clave que la instalacion no
            // conoce no es un error del cliente sino una consulta que no
            // encuentra a nadie. Validarla contra el llavero convertiria el
            // parametro en un oraculo de que claves hay configuradas.
            'key_id' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{2}$/'],
        ];
    }

    public function toQuery(): CredentialStatusQuery
    {
        $employeeUuid = $this->input('employee_uuid');
        $keyId = $this->input('key_id');

        return new CredentialStatusQuery(
            pendingOnly: $this->boolean('pending'),
            employeeUuid: \is_string($employeeUuid) ? $employeeUuid : null,
            keyId: \is_string($keyId) && $keyId !== '' ? $keyId : null,
        );
    }
}
