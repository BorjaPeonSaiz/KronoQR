<?php

declare(strict_types=1);

namespace App\Support\Observability\Metrics;

use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricStorage;
use Illuminate\Contracts\Redis\Factory as Redis;
use Prometheus\MetricFamilySamples;
use Throwable;

/**
 * `kronoqr_metrics_store_up`: whether the store that holds almost every series
 * of `/metrics` —Redis— answers right now (R3-CH-01, R4-DV-01).
 *
 * ## Why an explicit series and not `absent()`
 *
 * When Redis goes down, the series that live in it do not drop to zero: they
 * vanish. An alert such as "kiosk without heartbeat" then RESOLVES exactly when
 * things got worse, and `absent()` cannot tell "Redis is down" from "this
 * installation has no kiosks". This series is computed at scrape time and
 * outside Redis, so it is there precisely when the rest is not: 1 when Redis
 * answered a `PING`, 0 when it did not. No labels: host and port would be
 * topology in a diagnostics package (hard rule 21).
 *
 * With the Redis circuit breaker open the `PING` fails without touching the
 * network, so a scrape during an outage costs nothing.
 */
final readonly class MetricsStoreProbe
{
    private const string SERIES = 'kronoqr_metrics_store_up';

    public function __construct(private Redis $redis) {}

    public function isUp(): bool
    {
        try {
            $this->redis->connection()->command('PING', []);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function family(bool $up): ?MetricFamilySamples
    {
        $definition = $this->definition();

        if (! $definition instanceof MetricDefinition) {
            return null;
        }

        return new MetricFamilySamples([
            'name' => $definition->name,
            'type' => $definition->type->value,
            'help' => $definition->help,
            'labelNames' => $definition->labels,
            'samples' => [[
                'name' => $definition->name,
                'labelNames' => [],
                'labelValues' => [],
                'value' => $up ? 1 : 0,
            ]],
        ]);
    }

    /**
     * From the catalogue, not written twice: same reason as
     * `QueueDepthCollector::definition()`.
     */
    private function definition(): ?MetricDefinition
    {
        foreach (MetricCatalogue::all() as $definition) {
            if ($definition->storage === MetricStorage::Runtime && $definition->name === self::SERIES) {
                return $definition;
            }
        }

        return null;
    }
}
