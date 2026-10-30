<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Listener;

use App\Modules\Identity\Application\UseCase\IssueCredentialForRehiredEmployee;
use App\Modules\Workforce\Domain\Event\EmployeeRehired;

/**
 * Escucha la readmision de una persona y le emite la tarjeta nueva (RN-27,
 * ADR-062 §6).
 *
 * El mismo patron que {@see RevokeCredentialsOnOffboarding}: `Workforce` decide
 * la readmision y no importa `Identity`; `Identity` es la duena de las
 * credenciales y lee el EVENTO, nada mas. **Sincrono y sin `ShouldQueue`**
 * (ADR-027): corre dentro de la transaccion de la readmision, y si la emision
 * falla la readmision no se confirma.
 */
final readonly class IssueCredentialOnRehire
{
    public function __construct(private IssueCredentialForRehiredEmployee $issue) {}

    public function handle(EmployeeRehired $event): void
    {
        $this->issue->handle($event->employeeUuid, $event->credentialUuid, $event->actorUserId, $event->occurredAt());
    }
}
