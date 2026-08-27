<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Domain\Event\DomainEvent;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica los eventos de plantilla en el bus de Laravel.
 *
 * Es el adaptador del puerto {@see WorkforceEventPublisher}: existe para que el
 * caso de uso pueda emitir sin importar el framework (doc 02 §3.5, verificado
 * por Deptrac) y para que una prueba pueda sustituirlo por un doble que
 * simplemente recuerde lo publicado.
 *
 * **El evento se despacha por su clase**, que es lo que permite a `Identity`
 * (revocacion de credencial, tarea 1.5), `Compliance` (asiento de `audit_log`,
 * tarea 1.14) y `Reporting` suscribirse sin que este modulo sepa que existen.
 */
final readonly class LaravelWorkforceEventPublisher implements WorkforceEventPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
