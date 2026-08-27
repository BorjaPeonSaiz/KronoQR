<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Adapter;

use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Publica los eventos de fichaje en el bus de Laravel.
 *
 * Es el adaptador del puerto {@see EventPublisher}: existe para que el caso de
 * uso pueda emitir sin importar el framework (doc 02 §3.5, verificado por
 * Deptrac) y para que una prueba pueda sustituirlo por un doble que
 * simplemente recuerde lo publicado.
 *
 * **El evento se despacha por su clase**, que es lo que permite a `Compliance`
 * —el asiento de `audit_log`— y a `Reporting` suscribirse sin que `Attendance`
 * sepa que existen. `Attendance` no llama a nadie: emite (doc 02 §1.6).
 *
 * **Se publica DENTRO de la transaccion del caso de uso, y hay que saberlo para
 * escribir un listener.** El despachador de Laravel es sincrono, asi que un
 * listener que escriba en la base de datos —el de `audit_log`— entra en la misma
 * transaccion y, si falla, **deshace el fichaje**. Es la mitad de la garantia de
 * la regla dura 6: un fichaje que ocurre sin dejar traza es peor que un fichaje
 * que no ocurre, porque el segundo se puede corregir (ADR-027).
 *
 * > **Un listener con efectos fuera de la base de datos tiene que ser encolado
 * > con `$afterCommit`.** Difundir por Reverb al panel en vivo (tarea 1.11),
 * > enviar una notificacion o llamar a un sistema externo desde un listener
 * > sincrono significa hacerlo sobre una escritura que todavia puede revertir, y
 * > el panel acabaria mostrando un fichaje que no ocurrio. Laravel lo resuelve
 * > con `ShouldQueue` y `public bool $afterCommit = true`.
 */
final readonly class LaravelEventBus implements EventPublisher
{
    public function __construct(private Dispatcher $events) {}

    #[\Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}
