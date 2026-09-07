<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Adapter;

use App\Modules\Kiosk\Application\Port\KioskEventPublisher;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica los eventos de `Kiosk` en el bus de Laravel.
 *
 * Adaptador del puerto {@see KioskEventPublisher}: existe para que el caso de uso
 * pueda emitir sin importar el framework (doc 02 §3.5, verificado por Deptrac) y
 * para que una prueba pueda sustituirlo por un doble que recuerde lo publicado.
 *
 * **El evento se despacha por su clase**, que es lo que permite a `Compliance`
 * sellar el asiento de `audit_log` sin que este modulo sepa que existe.
 *
 * **Sincrono y sin cola.** El listener no implementa `ShouldQueue` y este
 * publicador no difiere nada: el asiento tiene que caer en la misma transaccion
 * que el alta del quiosco (regla dura 6, ADR-027).
 */
final readonly class LaravelKioskEventPublisher implements KioskEventPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
