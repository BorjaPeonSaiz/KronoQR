<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\QueueManager;

/**
 * El conector del driver `failover-after-commit` (ver {@see AfterCommitFailoverQueue}).
 */
final readonly class AfterCommitFailoverConnector implements ConnectorInterface
{
    public function __construct(
        private QueueManager $manager,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array{connections: list<string>}  $config
     */
    #[\Override]
    public function connect(array $config): AfterCommitFailoverQueue
    {
        return new AfterCommitFailoverQueue($this->manager, $this->events, $config['connections']);
    }
}
