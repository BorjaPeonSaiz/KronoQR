<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Metrics;

use App\Modules\Product\Application\Port\LicenseMetrics;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `license_limit_exceeded_total{limit}` sobre Redis (doc 02 §8.2, ADR-028).
 *
 * Mismo soporte y mismo motivo que las otras dos series de `Product`: es un
 * contador acumulativo que se incrementa en una peticion y que ninguna tarea
 * programada podria recalcular leyendo la tabla, porque la tabla solo guarda el
 * plan de hoy y no cuantas altas se hicieron por encima de el.
 *
 * **Un fallo de Redis no puede afectar a nada.** Se traga la excepcion, y aqui
 * el silencio es especialmente importante: quien llega hasta esta linea acaba de
 * dar de alta a una persona (ADR-028). La evidencia que sostiene la reclamacion
 * comercial no es esta metrica, es el asiento de `audit_log`, que si es sincrono
 * y transaccional.
 */
final readonly class RedisLicenseMetrics implements LicenseMetrics
{
    /** Prefijo comun para que `/metrics` encuentre todas las series con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string LIMIT_EXCEEDED_TOTAL = self::KEY_PREFIX.'license_limit_exceeded_total';

    public function __construct(private Redis $redis) {}

    public function limitExceeded(PlanLimit $limit): void
    {
        try {
            $this->redis->connection()->command('INCRBY', [
                self::LIMIT_EXCEEDED_TOTAL.':limit='.$limit->value,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado: ver el docblock de la clase.
        }
    }
}
