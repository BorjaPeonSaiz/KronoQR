<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\UseCase\CredentialView;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Credential` del contrato.
 *
 * **Envuelve el modelo de dominio y nunca el modelo Eloquent.** Es lo que hace
 * imposible que `secret_hash` acabe en una respuesta el dia que alguien anada un
 * campo: no esta en el objeto que se serializa. Y el token en claro no esta en
 * ninguno de los dos, porque no existe fuera de la emision.
 *
 * @property-read CredentialView $resource
 */
final class CredentialResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CredentialView $view */
        $view = $this->resource;
        $credential = $view->credential;

        return [
            'uuid' => $credential->uuid,
            'employee_uuid' => $view->employeeUuid,
            'key_id' => $credential->keyId,
            'issued_at' => self::utc($credential->issuedAt),
            'printed_at' => self::utc($credential->printedAt),
            'delivered_at' => self::utc($credential->deliveredAt),
            'revoked_at' => self::utc($credential->revokedAt),
            'revoked_reason' => $credential->revokedReason,
            'status' => $credential->isActive() ? 'active' : 'revoked',
        ];
    }

    /**
     * El formato `UtcTimestamp` del contrato: sufijo `Z`, nunca un desfase
     * explicito (regla dura 3).
     *
     * `setTimezone(UTC)` aunque el instante ya venga en UTC —lo esta, porque
     * `APP_TIMEZONE=UTC` y las columnas son `TIMESTAMPTZ`—: sin la conversion, un
     * dia en que alguien cambie esa configuracion la `Z` seria una mentira, y una
     * hora mal etiquetada en un registro con valor legal no se detecta a ojo.
     */
    private static function utc(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
