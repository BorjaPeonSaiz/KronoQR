<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Port\ReportingEventPublisher;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica los eventos de `Reporting` en el bus de Laravel.
 *
 * Adaptador del puerto {@see ReportingEventPublisher}: existe para que el caso de
 * uso pueda emitir sin importar el framework (doc 02 §3.5, verificado por
 * Deptrac) y para que una prueba pueda sustituirlo por un doble que recuerde lo
 * publicado.
 *
 * **El evento se despacha por su clase**, que es lo que permite a `Compliance`
 * sellar el asiento de `audit_log` sin que este modulo sepa que existe.
 *
 * **Sincrono y sin cola.** El listener no implementa `ShouldQueue` y este
 * publicador no difiere nada: el asiento tiene que caer en la misma transaccion
 * que el hecho (regla dura 6, ADR-027). Un fichero con las horas de la plantilla
 * no se genera ni se entrega sin rastro.
 */
final readonly class LaravelReportingEventPublisher implements ReportingEventPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
