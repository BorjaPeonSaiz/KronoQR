<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\Port\SupportTokenIssuer;
use App\Modules\Product\Domain\Event\SupportAccessRevoked;
use DateTimeImmutable;

/**
 * Retira los accesos de soporte **vigentes** que concedio una cuenta de gestion
 * que se acaba de dar de baja (**RF-ID-10**, RF-PD-11, ADR-020).
 *
 * **Un acceso temporal no sobrevive a quien respondia de el.** Lo concede un
 * `admin` del cliente y es la firma del encargo de tratamiento (RL-18): si esa
 * persona deja el hotel, el fabricante no puede seguir entrando con una
 * autorizacion que ya no tiene a nadie detras.
 *
 * **No abre transaccion**: se ejecuta dentro de la de la baja, con el candado
 * de la cadena ya tomado (lo invoca el listener sincrono del evento de
 * `Identity`). Si algo falla aqui, la baja entera se deshace: o caen la cuenta y
 * sus accesos, o no cae nada.
 *
 * **Cada acceso retirado deja su propio asiento** (`support_grant.revoked`),
 * atribuido a **quien hizo la baja** —`revoked_by_user_id`, nulo si fue por
 * consola— y con `cause: account_deactivated` y el `uuid` de la cuenta dada de
 * baja en el payload: leido meses despues, el asiento explica por que se corto
 * un acceso que nadie retiro a mano.
 */
final readonly class RevokeSupportGrantsOfDeactivatedAccount
{
    public const string CAUSE = 'account_deactivated';

    public function __construct(
        private SupportGrantRepository $grants,
        private SupportTokenIssuer $tokens,
        private ProductEventPublisher $events,
    ) {}

    /**
     * @param  string|null  $actorUuid  Quien hizo la baja; `null` desde la consola.
     * @return int Cuantos accesos se han retirado.
     */
    public function handle(string $accountUuid, ?string $actorUuid, DateTimeImmutable $now): int
    {
        $revokedBy = $actorUuid === null ? null : $this->grants->authorByUuid($actorUuid)?->id;
        $revoked = 0;

        foreach ($this->grants->active($now) as $grant) {
            if ($grant->grantedBy->uuid !== $accountUuid || $grant->id === null) {
                continue;
            }

            // Escritura condicionada por `revoked_at IS NULL`, como en la
            // revocacion del panel: si otra peticion lo retiro a la vez, no se
            // escribe un segundo asiento del mismo hecho.
            if ($this->grants->markRevoked($grant->id, $now, $revokedBy) !== 1) {
                continue;
            }

            $this->tokens->revokeAllFor($grant);

            $this->events->publish(new SupportAccessRevoked(
                grantId: $grant->id,
                grantUuid: $grant->uuid,
                scope: $grant->scope->value,
                revokedByUserId: $revokedBy,
                wasActive: true,
                occurredAt: $now,
                cause: self::CAUSE,
                deactivatedAccountUuid: $accountUuid,
            ));

            $revoked++;
        }

        return $revoked;
    }
}
