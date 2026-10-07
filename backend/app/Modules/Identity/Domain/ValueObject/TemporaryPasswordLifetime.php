<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Cuanto vive una contrasena temporal antes de dejar de servir para entrar
 * (**RF-ID-10**, `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS`).
 *
 * **Una contrasena temporal que no caduca acaba siendo la definitiva**, y es
 * una credencial que conocen dos personas: quien la emitio y quien la recibio
 * (ASVS 2.3.1). Por eso tiene vida, y por eso la vida tiene techo.
 *
 * **Entre 1 y 168 horas.** Menos de una hora no deja tiempo a entregarla en mano
 * en un hotel con turnos; mas de una semana es una contrasena compartida a
 * largo plazo con otro nombre. El valor lo fija cada instalacion (regla dura 13)
 * y llega aqui ya resuelto: el dominio no lee configuracion.
 */
final readonly class TemporaryPasswordLifetime
{
    public const int MIN_HOURS = 1;

    public const int MAX_HOURS = 168;

    public function __construct(public int $hours)
    {
        if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
            throw new InvalidArgumentException(
                'La vida de una contrasena temporal tiene que estar entre '.self::MIN_HOURS.' y '
                .self::MAX_HOURS.' horas; se ha recibido '.$hours.'.',
            );
        }
    }

    /**
     * El instante en que deja de servir una temporal emitida en `$issuedAt`.
     *
     * **Horas transcurridas, no horas de reloj de pared.** Se suma sobre el
     * instante absoluto (segundos desde la epoca) y no con `modify('+N hours')`
     * sobre la zona del instante: una emision la vispera de un cambio de hora
     * caduca exactamente `N × 3600` segundos despues, ni una hora antes ni una
     * despues. El instante conserva la zona con la que llego.
     */
    public function expiresAt(DateTimeImmutable $issuedAt): DateTimeImmutable
    {
        return $issuedAt->setTimestamp($issuedAt->getTimestamp() + $this->hours * 3600);
    }
}
