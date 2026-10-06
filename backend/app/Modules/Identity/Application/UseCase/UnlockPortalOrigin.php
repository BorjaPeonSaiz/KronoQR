<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use App\Modules\Shared\Application\Port\AuthenticationJournal;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;

/**
 * **Levanta a mano el bloqueo por origen del portal** (`identity:origin-unlock`,
 * ADR-050 §2, dictamen de seguridad M3).
 *
 * Existe por el residuo 2 de ADR-050: tras el NAT del hotel o un CGNAT, veinte
 * fallos ajenos cierran el portal a toda la plantilla una hora, y sin esto la
 * unica salida era esperar o tocar umbrales. Borra la cuenta de fallos y el
 * bloqueo de ese origen, y nada mas: no toca el contador de ningun empleado.
 *
 * **Primero el asiento, despues el borrado.** `auth.origin_unlocked` es sincrono:
 * si no se puede escribir, el bloqueo sigue puesto (regla dura 6). El asiento
 * lleva el `ip_hash` del origen y nunca la IP en claro.
 */
final readonly class UnlockPortalOrigin
{
    public function __construct(
        private PortalOriginAttempts $origins,
        private AuthenticationJournal $journal,
    ) {}

    /**
     * @return bool Si habia cuenta o bloqueo que borrar.
     */
    public function handle(RequestOrigin $origin): bool
    {
        $history = $this->origins->historyFor($origin);
        $hadState = $history->failures !== [] || $history->lockedUntil !== null;

        $this->journal->originUnlocked(AuthChannel::PORTAL, $origin->key(), $hadState);

        $this->origins->forget($origin);

        return $hadState;
    }
}
