<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Query;

use App\Modules\Kiosk\Domain\ValueObject\ClaimOutcome;
use DateTimeImmutable;

/**
 * El desenlace de un sondeo de la tablet (`POST /api/v1/kiosk/pair/claim`,
 * **RF-PD-06**).
 *
 * ## `token` es lo unico que no se puede volver a pedir
 *
 * Viaja **en claro y una sola vez**: el servidor guarda su hash y no puede
 * volver a enseñarlo. Si la tablet lo pierde, el camino es desvincular y volver a
 * emparejar. Por eso este objeto no se registra en ningun log ni se guarda en
 * ningun sitio — se serializa en la respuesta y se olvida.
 *
 * ## Los tres constructores dicen que se puede rellenar
 *
 * `pending()` y `rejected()` no admiten dispositivo ni token, y `paired()` los
 * exige. Con un unico constructor de campos opcionales, un `Pending` con token
 * relleno seria expresable — y bastaria un `if` mal escrito para entregarlo.
 */
final readonly class PairingClaimResult
{
    private function __construct(
        public ClaimOutcome $outcome,
        public ?string $deviceUuid,
        public ?string $deviceName,
        public ?string $token,
        public ?DateTimeImmutable $tokenExpiresAt,
    ) {}

    /** Nadie ha confirmado todavia. La tablet sigue mostrando el codigo. */
    public static function pending(): self
    {
        return new self(ClaimOutcome::Pending, null, null, null, null);
    }

    /** Confirmada: el dispositivo esta vinculado y este es su token. */
    public static function paired(
        string $deviceUuid,
        string $deviceName,
        string $token,
        DateTimeImmutable $tokenExpiresAt,
    ): self {
        return new self(ClaimOutcome::Paired, $deviceUuid, $deviceName, $token, $tokenExpiresAt);
    }

    /**
     * **Una sola forma para las tres causas** —solicitud desconocida, secreto que
     * no coincide, y caducada o ya consumida— y sin ningun campo donde alojar
     * cual fue (regla dura 17, RS-03).
     */
    public static function rejected(): self
    {
        return new self(ClaimOutcome::Rejected, null, null, null, null);
    }
}
