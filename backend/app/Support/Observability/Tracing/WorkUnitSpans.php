<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use App\Modules\Shared\Application\Support\SpanScope;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Context as LogContext;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Un span y un `trace_id` por orden de Artisan y por trabajo de cola (L2,
 * tarea 10.6, doc 02 §8.1).
 *
 * ## Lo que faltaba
 *
 * Solo el planificador abria un span, y en el proceso de `schedule:run`: la
 * orden que este lanza corre en OTRO proceso, sin span y sin `trace_id`, asi que
 * las lineas de la conciliacion o de la deteccion salian sin identificador con
 * el que agruparlas. Las ordenes lanzadas a mano y los trabajos de Horizon,
 * igual.
 *
 * ## Que hace
 *
 * - **Una orden de Artisan** abre un span `artisan <orden>` (sin argumentos:
 *   pueden llevar un codigo de empleado). **No lo activa**: una orden puede
 *   morir sin emitir `CommandFinished`, y un contexto activado y nunca soltado
 *   se queda en la pila del proceso. Lo que agrupa sus lineas es el `trace_id`
 *   que deja en el log.
 * - **Un trabajo de cola** abre `job <clase>` como hijo de la traza que viajo
 *   con el (`QueuedTraceContext`), tampoco activado y por lo mismo.
 * - **El `trace_id` va al `Context` del log** mientras dura la unidad de
 *   trabajo. Con trazas exportadas es el del span; **sin SDK —la instalacion de
 *   la mayoria— el span es inerte y se usa uno aleatorio**, que es lo que hace
 *   que el log del planificador se pueda seguir igual. Al terminar se restaura
 *   el que hubiera: una orden lanzada con `Artisan::call` desde una peticion no
 *   le cambia el `trace_id` a la peticion.
 *
 * Una pila y no una sola ranura: las ordenes se anidan (`schedule:run` →
 * `Schedule::call` → `Artisan::call`). Todo envuelto: medir no puede impedir
 * que una orden o un trabajo se ejecuten.
 */
final class WorkUnitSpans
{
    /** @var list<array{span: SpanScope, previous: mixed}> */
    private array $stack = [];

    public function commandStarting(CommandStarting $event): void
    {
        $name = $event->command !== '' ? $event->command : 'artisan';

        $this->open(
            SpanScope::start('kronoqr.console', 'artisan '.$name, SpanKind::KIND_INTERNAL, ['artisan.command' => $name]),
        );
    }

    public function commandFinished(CommandFinished $event): void
    {
        $this->close(['artisan.exit_code' => $event->exitCode]);
    }

    public function jobProcessing(Job $job): void
    {
        $name = $job->resolveName();

        $this->open(
            SpanScope::start('kronoqr.queue', 'job '.$name, SpanKind::KIND_CONSUMER, [
                'messaging.destination.name' => $job->getQueue(),
                'queue.job' => $name,
            ]),
        );
    }

    public function jobFinished(bool $failed): void
    {
        $this->close(['queue.outcome' => $failed ? 'failed' : 'processed']);
    }

    private function open(SpanScope $span): void
    {
        try {
            $previous = LogContext::get(TraceContext::TRACE_ID_KEY);
            $traceId = $span->traceId() ?? (is_string($previous) && $previous !== '' ? $previous : bin2hex(random_bytes(16)));

            LogContext::add(TraceContext::TRACE_ID_KEY, $traceId);
            $this->stack[] = ['span' => $span, 'previous' => $previous];
        } catch (Throwable) {
            $span->end();
        }
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function close(array $attributes): void
    {
        $unit = array_pop($this->stack);

        if ($unit === null) {
            return;
        }

        $unit['span']->end($attributes);

        try {
            \is_string($unit['previous'])
                ? LogContext::add(TraceContext::TRACE_ID_KEY, $unit['previous'])
                : LogContext::forget(TraceContext::TRACE_ID_KEY);
        } catch (Throwable) {
            // Ver el docblock de la clase.
        }
    }
}
