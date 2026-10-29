<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Metrics;

use App\Modules\Workforce\Application\Port\OffboardingMetrics;
use DateTimeImmutable;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * Las dos series de la tarea de las bajas programadas (ADR-062 §4), en Redis
 * bajo el prefijo comun que lee `GET /metrics` (`MetricCatalogue`).
 *
 * - `kronoqr_offboardings_applied_total{outcome}`: `HINCRBY` sobre un HASH por
 *   desenlace.
 * - `kronoqr_scheduled_offboardings_overdue`: `HSET` del recuento, porque es un
 *   estado que la pasada reescribe entera, tambien cuando vale 0.
 *
 * **No lanza.** Cuando se llega aqui la baja ya esta confirmada o aplazada, y un
 * Redis caido no puede convertir eso en un fallo de la tarea. Sin Redis la serie
 * desaparece, y `kronoqr_metrics_store_up` lo dice.
 */
final readonly class RedisOffboardingMetrics implements OffboardingMetrics
{
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string APPLIED_TOTAL = self::KEY_PREFIX.'kronoqr_offboardings_applied_total';

    public const string OVERDUE = self::KEY_PREFIX.'kronoqr_scheduled_offboardings_overdue';

    public const string LAST_RUN = self::KEY_PREFIX.'kronoqr_scheduled_offboardings_last_run_timestamp_seconds';

    /** El campo del HASH de una serie sin etiquetas. */
    public const string UNLABELLED = 'value';

    public function __construct(private Redis $redis) {}

    public function offboardingProcessed(string $outcome): void
    {
        $this->command('HINCRBY', [self::APPLIED_TOTAL, 'outcome='.$outcome, 1]);
    }

    public function overdueScheduledOffboardings(int $count): void
    {
        $this->command('HSET', [self::OVERDUE, self::UNLABELLED, max(0, $count)]);
    }

    public function passCompleted(DateTimeImmutable $at): void
    {
        $this->command('HSET', [self::LAST_RUN, self::UNLABELLED, $at->getTimestamp()]);
    }

    /** @param  list<int|string>  $parameters */
    private function command(string $command, array $parameters): void
    {
        try {
            $this->redis->connection()->command($command, $parameters);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo. Ver el docblock.
        }
    }
}
