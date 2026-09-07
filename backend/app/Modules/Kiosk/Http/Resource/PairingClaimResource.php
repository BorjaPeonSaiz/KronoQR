<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\Query\PairingClaimResult;
use App\Modules\Kiosk\Domain\ValueObject\ClaimOutcome;
use App\Modules\Kiosk\Http\Response\PairingRejectedResponse;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * Serializa el `200` de `POST /api/v1/kiosk/pair/claim`: el esquema
 * `PairingClaim`, union discriminada por `status`.
 *
 * **Dos formas y no una con campos opcionales.** Con `device` y `token` marcados
 * como opcionales, un cliente podria leerlos sin comprobar `status` y guardar un
 * `undefined` como token; asi el cliente generado obliga a ramificar y `vue-tsc`
 * no deja olvidarlo. Es la misma decision que `ScanAccepted` frente a
 * `ScanDebounced` (ADR-031).
 *
 * **El rechazo no pasa por aqui**: viaja como `422` con
 * {@see PairingRejectedResponse}. Si fuera una
 * tercera rama de este esquema, existiria un sitio donde escribir la causa (regla
 * dura 17).
 *
 * **El token va en claro y es la unica vez.** El servidor guarda su hash y no
 * puede volver a enseñarlo. Por eso este recurso no se registra en ningun log: se
 * serializa y se olvida.
 *
 * @property-read PairingClaimResult $resource
 */
final class PairingClaimResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PairingClaimResult $result */
        $result = $this->resource;

        if ($result->outcome === ClaimOutcome::Pending) {
            return ['status' => 'pending'];
        }

        if ($result->outcome !== ClaimOutcome::Paired
            || $result->deviceUuid === null
            || $result->deviceName === null
            || $result->token === null
            || $result->tokenExpiresAt === null
        ) {
            // El rechazo no se serializa aqui y nunca deberia llegar: si llega,
            // fallar en voz alta es lo correcto. Devolver un cuerpo a medias
            // dejaria a la tablet guardando un token vacio y fichando contra un
            // `401` en cada escaneo.
            throw new RuntimeException('Un claim rechazado no se serializa con PairingClaimResource.');
        }

        return [
            'status' => 'paired',
            'device' => [
                'uuid' => $result->deviceUuid,
                'name' => $result->deviceName,
            ],
            'token' => [
                'value' => $result->token,
                'expires_at' => self::utc($result->tokenExpiresAt),
            ],
        ];
    }

    /**
     * El formato `UtcTimestamp` del contrato: sufijo `Z`, nunca un desfase
     * explicito (regla dura 3).
     *
     * `setTimezone(UTC)` aunque el instante ya venga en UTC —lo esta, porque
     * `APP_TIMEZONE=UTC` y las columnas son `TIMESTAMPTZ`—: sin la conversion, un
     * dia en que alguien cambie esa configuracion la `Z` seria una mentira, y una
     * hora mal etiquetada no se detecta a ojo. Es el mismo criterio que documenta
     * `Identity\\Http\\Resource\\CredentialResource`.
     */
    private static function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
