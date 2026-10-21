<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/** El trabajo que saca {@see InMemoryQueue}: como un `RedisJob`, sin Redis. */
final class InMemoryJob extends Job implements JobContract
{
    public function __construct(
        Container $container,
        private readonly InMemoryQueue $memory,
        private readonly string $payload,
        private readonly int $attempts,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->memory->requeue($this->payload, $this->attempts, $this->queue, (int) $delay);
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function getJobId(): string
    {
        $uuid = $this->uuid();

        return is_string($uuid) ? $uuid : '';
    }

    public function getRawBody(): string
    {
        return $this->payload;
    }
}
