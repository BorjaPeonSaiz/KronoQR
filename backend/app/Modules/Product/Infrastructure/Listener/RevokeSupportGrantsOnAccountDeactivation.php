<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Listener;

use App\Modules\Identity\Domain\Event\ManagementAccountDeactivated;
use App\Modules\Product\Application\UseCase\RevokeSupportGrantsOfDeactivatedAccount;

/**
 * La baja de una cuenta de gestion retira los accesos de soporte vigentes que
 * esa cuenta concedio (RF-ID-10, RF-PD-11).
 *
 * **Sincrono, sin cola y sin `afterCommit`**: corre dentro de la transaccion de
 * la baja (ADR-010). Es la misma via que el resto de aristas de `Product` hacia
 * `Identity`: de solo lectura y solo sobre EVENTOS (doc 02 §1.6).
 */
final readonly class RevokeSupportGrantsOnAccountDeactivation
{
    public function __construct(private RevokeSupportGrantsOfDeactivatedAccount $revoke) {}

    public function handle(ManagementAccountDeactivated $event): void
    {
        $this->revoke->handle($event->userUuid, $event->actorUuid, $event->occurredAt());
    }
}
