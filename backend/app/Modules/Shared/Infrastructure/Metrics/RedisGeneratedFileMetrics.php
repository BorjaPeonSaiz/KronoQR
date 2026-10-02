<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics;

use App\Modules\Shared\Application\Port\GeneratedFileMetrics;
use App\Modules\Shared\Domain\ValueObject\GeneratedFileClass;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * Las cuatro series de los ficheros generados sobre Redis (ADR-045 §h, C10).
 *
 * Un HASH por serie con la etiqueta como campo (`class=data_export`), que es la
 * forma `LabelledHash` del catalogo de `/metrics`. Los contadores con `HINCRBY`;
 * el gauge `generated_files_overdue` con `HSET`, porque es un estado que la
 * pasada horaria recalcula entero y no un acontecimiento.
 *
 * **La etiqueta sale del enumerado**, nunca de una cadena libre: no hay forma de
 * que un `uuid` o una ruta acaben en una serie (regla dura 21).
 *
 * **Medir no puede romper una purga.** Si Redis no responde, la purga sigue: un
 * punto de menos en una serie es infinitamente mas barato que un fichero con
 * datos personales que se queda en el disco porque el contador fallo.
 */
final readonly class RedisGeneratedFileMetrics implements GeneratedFileMetrics
{
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string ORPHANS_REMOVED_TOTAL = self::KEY_PREFIX.'generated_files_orphans_removed_total';

    public const string REFUSED_TOTAL = self::KEY_PREFIX.'generated_files_refused_total';

    public const string MISSING_TOTAL = self::KEY_PREFIX.'generated_files_missing_total';

    public const string OVERDUE = self::KEY_PREFIX.'generated_files_overdue';

    public const string REMOVE_FAILED_TOTAL = self::KEY_PREFIX.'generated_files_remove_failed_total';

    public function __construct(private Redis $redis) {}

    public function orphanRemoved(GeneratedFileClass $class): void
    {
        $this->command('HINCRBY', [self::ORPHANS_REMOVED_TOTAL, self::field($class), 1]);
    }

    public function refused(GeneratedFileClass $class): void
    {
        $this->command('HINCRBY', [self::REFUSED_TOTAL, self::field($class), 1]);
    }

    public function missing(GeneratedFileClass $class): void
    {
        $this->command('HINCRBY', [self::MISSING_TOTAL, self::field($class), 1]);
    }

    public function removeFailed(GeneratedFileClass $class): void
    {
        $this->command('HINCRBY', [self::REMOVE_FAILED_TOTAL, self::field($class), 1]);
    }

    public function overdue(GeneratedFileClass $class, int $count): void
    {
        $this->command('HSET', [self::OVERDUE, self::field($class), max(0, $count)]);
    }

    private static function field(GeneratedFileClass $class): string
    {
        return 'class='.$class->value;
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
