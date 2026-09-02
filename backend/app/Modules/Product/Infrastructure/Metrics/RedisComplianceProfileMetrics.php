<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Metrics;

use App\Modules\Product\Application\Port\ComplianceProfileMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `compliance_profile_changes_total{effect}` sobre Redis (doc 02 §8.2).
 *
 * Mismo soporte y mismo motivo que `RedisSettingsMetrics`: es un contador
 * acumulativo que se incrementa dentro de una peticion HTTP y no un gauge que
 * una tarea programada pueda recalcular leyendo la tabla —`compliance_profiles`
 * solo guarda el valor de hoy, no cuantas veces se cambio—. `INCRBY` es atomico.
 *
 * Tres etiquetas y no dos booleanos separados: `any` cuenta todos los campos
 * cambiados, `incident_detection` y `retention` los que ademas tienen esa
 * consecuencia. Un cambio puede contar en dos, y eso es correcto: son
 * consecuencias, no categorias excluyentes.
 */
final readonly class RedisComplianceProfileMetrics implements ComplianceProfileMetrics
{
    /** Prefijo comun para que el endpoint `/metrics` encuentre todas las series con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string CHANGES_TOTAL = self::KEY_PREFIX.'compliance_profile_changes_total';

    public function __construct(private Redis $redis) {}

    public function profileChanged(int $changes, int $affectingIncidentDetection, int $affectingRetention): void
    {
        $this->increment('any', $changes);
        $this->increment('incident_detection', $affectingIncidentDetection);
        $this->increment('retention', $affectingRetention);
    }

    private function increment(string $effect, int $by): void
    {
        if ($by < 1) {
            return;
        }

        try {
            $this->redis->connection()->command('INCRBY', [self::CHANGES_TOTAL.':effect='.$effect, $by]);
        } catch (Throwable) {
            // Silencio deliberado y acotado: ver el docblock del puerto.
        }
    }
}
