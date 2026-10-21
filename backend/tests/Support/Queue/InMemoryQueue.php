<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;

/**
 * Una cola de verdad para las pruebas que necesitan `Worker::process` sobre el
 * payload que construye Laravel (ADR-059 §5).
 *
 * Un `Job` simulado no sirve para eso: lo que hay que probar es lo que hace el
 * worker con las opciones que el despachador copia del listener (`tries`,
 * `maxExceptions`, `retryUntil`) y con el `createdAt` del payload. Esta cola
 * usa `createPayload()` y `enqueueUsing()` del framework, igual que
 * `RedisQueue`, y solo cambia donde se guarda: un array en memoria. La espera
 * de `later()` y de `release()` se apunta, pero no se espera: quien prueba
 * mueve el reloj.
 */
final class InMemoryQueue extends Queue implements QueueContract
{
    /** @var list<array{payload: string, attempts: int, queue: string, delay: int}> */
    private array $jobs = [];

    /** @var list<int> Las esperas con que se ha devuelto cada trabajo. */
    public array $releases = [];

    public function size($queue = null): int
    {
        return count($this->jobs);
    }

    public function pendingSize($queue = null): int
    {
        return count($this->jobs);
    }

    public function delayedSize($queue = null): int
    {
        return 0;
    }

    public function reservedSize($queue = null): int
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->queueName($queue), $data),
            $queue,
            null,
            fn (string $payload, ?string $queue): mixed => $this->pushRaw($payload, $queue),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $this->store($payload, 0, $this->queueName($queue), 0);

        return null;
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        $seconds = $delay instanceof DateTimeInterface || $delay instanceof DateInterval ? 0 : (int) $delay;
        $this->store($this->createPayload($job, $this->queueName($queue), $data, $delay), 0, $this->queueName($queue), $seconds);

        return null;
    }

    public function pop($queue = null): ?JobContract
    {
        $next = array_shift($this->jobs);

        if ($next === null) {
            return null;
        }

        return new InMemoryJob($this->container, $this, $next['payload'], $next['attempts'] + 1, $this->getConnectionName(), $next['queue']);
    }

    /** Lo que hace `RedisQueue` al liberar: el mismo payload, un intento mas. */
    public function requeue(string $payload, int $attempts, string $queue, int $delay): void
    {
        $this->releases[] = $delay;
        $this->store($payload, $attempts, $queue, $delay);
    }

    private function store(string $payload, int $attempts, string $queue, int $delay): void
    {
        $this->jobs[] = ['payload' => $payload, 'attempts' => $attempts, 'queue' => $queue, 'delay' => $delay];
    }

    private function queueName(mixed $queue): string
    {
        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }
}
