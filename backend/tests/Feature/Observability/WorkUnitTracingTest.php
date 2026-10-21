<?php

declare(strict_types=1);

use App\Support\Observability\Tracing\TraceContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Observability\ProbeJob;
use Tests\Support\Telemetry\RecordingTracer;

/*
 * UN SPAN Y UN `trace_id` POR ORDEN DE ARTISAN Y POR TRABAJO DE COLA (L2,
 * tarea 10.6).
 *
 * La orden que lanza el planificador corre en su propio proceso: sin esto sus
 * lineas de log salian sin `trace_id` y no habia span con que agruparlas. Con
 * trazas exportadas, el `trace_id` es el del span; sin SDK, uno aleatorio, y en
 * los dos casos se restaura al terminar.
 *
 * Las ordenes se simulan con sus eventos, como los emite el kernel de consola
 * en un proceso de verdad (`Artisan::call` en una prueba no pasa por el).
 */

function workUnitTracingCommand(string $name, Closure $body): void
{
    $input = new ArrayInput(['codigo' => 'E7K2M9XQ4']);

    event(new CommandStarting($name, $input, new NullOutput));

    try {
        $body();
    } finally {
        event(new CommandFinished($name, $input, new NullOutput, 0));
    }
}

it('pone un trace_id en el log mientras dura una orden, tambien sin SDK, y lo restaura', function (): void {
    Context::forget(TraceContext::TRACE_ID_KEY);

    /** @var list<mixed> $vistos */
    $vistos = [];

    workUnitTracingCommand('attendance:reconcile', static function () use (&$vistos): void {
        $vistos[] = Context::get(TraceContext::TRACE_ID_KEY);
    });
    workUnitTracingCommand('attendance:reconcile', static function () use (&$vistos): void {
        $vistos[] = Context::get(TraceContext::TRACE_ID_KEY);
    });

    expect($vistos)->toHaveCount(2)
        ->and($vistos[0])->toBeString()->toMatch('/^[0-9a-f]{32}$/')
        // Dos ordenes, dos trazas.
        ->and($vistos[0])->not->toBe($vistos[1])
        // Y al terminar no queda nada colgado del proceso.
        ->and(Context::get(TraceContext::TRACE_ID_KEY))->toBeNull();
})->group('RNF-D-01', 'RQ-06');

it('no le cambia el trace_id a quien lanza la orden', function (): void {
    Context::add(TraceContext::TRACE_ID_KEY, '0af7651916cd43dd8448eb211c80319c');

    workUnitTracingCommand('product:doctor', static fn (): null => null);

    expect(Context::get(TraceContext::TRACE_ID_KEY))->toBe('0af7651916cd43dd8448eb211c80319c');

    Context::forget(TraceContext::TRACE_ID_KEY);
})->group('RNF-D-01');

it('abre un span por orden y por trabajo de cola, sin los argumentos de la orden', function (): void {
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        workUnitTracingCommand('credentials:print', static fn (): null => null);

        config()->set('queue.default', 'sync');
        ProbeJob::dispatch();

        $nombres = array_map(static fn (ImmutableSpan $span): string => $span->getName(), $tracer->finishedSpans());
        $volcado = (string) json_encode(array_map(static fn (ImmutableSpan $span): array => $span->getAttributes()->toArray(), $tracer->finishedSpans()));

        expect($nombres)->toContain('artisan credentials:print')
            ->and($nombres)->toContain('job '.ProbeJob::class)
            ->and($volcado)->not->toContain('E7K2M9XQ4');
    });
})->group('RNF-D-01', 'RS-05');
