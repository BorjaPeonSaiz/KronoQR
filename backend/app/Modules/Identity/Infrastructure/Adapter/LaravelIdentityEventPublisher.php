<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica los hechos de `Identity` en el bus de Laravel.
 *
 * **El evento se despacha por su clase**, que es lo que permite a `Compliance`
 * sellar el asiento de `audit_log` y a `Reporting` proyectar el panel de estado
 * de credenciales sin que este modulo sepa que existen (doc 02 §1.6).
 *
 * Los listeners de auditoria son **sincronos y en la transaccion de quien
 * publica**: ver {@see IdentityEventPublisher} sobre por que aqui eso es lo
 * contrario de lo que hace `Workforce`.
 */
final readonly class LaravelIdentityEventPublisher implements IdentityEventPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
