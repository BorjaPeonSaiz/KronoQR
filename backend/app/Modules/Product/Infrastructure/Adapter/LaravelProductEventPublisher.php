<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica los eventos de `Product` en el bus de Laravel.
 *
 * Adaptador del puerto {@see ProductEventPublisher}: existe para que el caso de
 * uso pueda emitir sin importar el framework (doc 02 §3.5, verificado por
 * Deptrac) y para que una prueba pueda sustituirlo por un doble que recuerde lo
 * publicado.
 *
 * **El evento se despacha por su clase**, que es lo que permite a `Compliance`
 * sellar el asiento de `audit_log` sin que este modulo sepa que existe.
 *
 * **Sincrono y sin cola.** El listener no implementa `ShouldQueue` y este
 * publicador no difiere nada: el asiento tiene que caer en la misma transaccion
 * que el cambio (regla dura 6).
 */
final readonly class LaravelProductEventPublisher implements ProductEventPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
