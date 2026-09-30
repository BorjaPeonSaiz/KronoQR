<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Listener;

use App\Modules\Identity\Application\UseCase\RevokeCredentialsOfOffboardedEmployee;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;

/**
 * Escucha la baja de una persona y le retira la credencial (RN-14, N1).
 *
 * `Workforce` decide el estado laboral y no puede importar `Identity`; `Identity`
 * es la duena de las credenciales y lee el EVENTO, nada mas (misma arista de
 * solo lectura que `Compliance` y `Product` ya tienen sobre
 * `WorkforceDomainEvent`).
 *
 * **Sincrono y sin `ShouldQueue`** (ADR-027): corre dentro de la transaccion de
 * la baja, y si la revocacion falla la baja no se confirma. Una persona de baja
 * con la tarjeta aun activa es justo lo que RN-14 prohibe. El span y el log los
 * pone el caso de uso.
 */
final readonly class RevokeCredentialsOnOffboarding
{
    public function __construct(private RevokeCredentialsOfOffboardedEmployee $revoke) {}

    public function handle(EmployeeOffboarded $event): void
    {
        $this->revoke->handle($event->employeeUuid, $event->occurredAt());
    }
}
