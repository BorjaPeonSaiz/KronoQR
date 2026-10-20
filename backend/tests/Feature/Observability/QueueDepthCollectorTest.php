<?php

declare(strict_types=1);

use App\Support\Observability\Metrics\QueueDepthCollector;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Prometheus\MetricFamilySamples;

/*
 * `queue_jobs_pending` frente a `queue_jobs_waiting` (bloque 9 de la 2.3.0,
 * revision): los trabajos reservados (en ejecucion, p. ej. una exportacion
 * integra de 45 min) cuentan como pendientes pero NO como en espera.
 */

/**
 * @param  list<MetricFamilySamples>  $families
 * @return array<string, array<string, float|int|string>>
 */
function queueDepthValues(array $families): array
{
    $values = [];

    foreach ($families as $family) {
        foreach ($family->getSamples() as $sample) {
            $values[$family->getName()][$sample->getLabelValues()[0]] = $sample->getValue();
        }
    }

    return $values;
}

it('publica en espera solo lo que aun no ha cogido un trabajador, y pendiente todo', function (): void {
    config()->set('horizon.defaults', ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default']]]);
    config()->set('horizon.environments', []);

    // Un trabajo reservado (la exportacion en curso) y ninguno esperando.
    $driver = Mockery::mock(RedisQueue::class);
    $driver->shouldReceive('size')->with('default')->andReturn(1);
    $driver->shouldReceive('pendingSize')->with('default')->andReturn(0);
    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->with('redis')->andReturn($driver);

    $values = queueDepthValues((new QueueDepthCollector($factory))->collect());

    expect((int) $values['queue_jobs_pending']['default'])->toBe(1)
        ->and((int) $values['queue_jobs_waiting']['default'])->toBe(0);
})->group('RQ-06');

it('no publica en espera con un driver que no sabe distinguirlo: ausente, no cero', function (): void {
    config()->set('horizon.defaults', ['supervisor-1' => ['connection' => 'sync', 'queue' => ['default']]]);
    config()->set('horizon.environments', []);

    $driver = Mockery::mock(Queue::class);
    $driver->shouldReceive('size')->andReturn(2);
    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->with('sync')->andReturn($driver);

    $values = queueDepthValues((new QueueDepthCollector($factory))->collect());

    expect($values)->toHaveKey('queue_jobs_pending')->not->toHaveKey('queue_jobs_waiting');
})->group('RQ-06');
