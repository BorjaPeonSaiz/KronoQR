<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\UseCase\IssuedCredential;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `IssuedCredential`: la credencial recien emitida,
 * **pendiente de imprimir**.
 *
 * **Ninguna respuesta de esta API lleva un payload de QR** (ADR-034). El token
 * se acuña al imprimir y sale del servidor dentro del PDF de la tarjeta, que es
 * el unico canal con el que ADR-014 cuenta. Por eso `key_id` es aqui siempre
 * `null`: hasta que no se firma no hay clave con la que se haya firmado.
 *
 * @property-read IssuedCredential $resource
 */
final class IssuedCredentialResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IssuedCredential $issued */
        $issued = $this->resource;
        $credential = $issued->credential;

        return [
            'uuid' => $credential->uuid,
            'employee_uuid' => $issued->employeeUuid,
            // Siempre `null`: la clave de firma se elige al imprimir, no al
            // emitir, para que una tarjeta emitida antes de una rotacion e
            // impresa despues salga firmada con la clave nueva (§5.3).
            'key_id' => null,
            'issued_at' => $credential->issuedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'printed_at' => null,
            'delivered_at' => null,
            'revoked_at' => null,
            'revoked_reason' => null,
            'status' => 'active',
            'reissue' => $issued->reissue,
        ];
    }
}
