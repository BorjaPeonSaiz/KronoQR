<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Metrics;

use App\Modules\Attendance\Application\Port\CorrectionMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `manual_corrections_total{reason_code}` sobre Redis (doc 02 §8.2).
 *
 * Mismo soporte que {@see RedisScanMetrics} y por el mismo motivo, aunque el
 * hecho medido sea mil veces menos frecuente: el endpoint `/metrics` de la tarea
 * 3.1 recoge todas las series con un solo `SCAN` sobre el prefijo comun, y una
 * metrica escrita en otro sitio se quedaria fuera sin que nadie lo notara.
 *
 * `HINCRBY` es atomico: dos responsables corrigiendo a la vez suman dos, no uno.
 *
 * **Un fallo de Redis no puede tumbar una correccion.** Se llega aqui con la
 * transaccion ya confirmada: el registro horario esta rectificado, la fila de
 * `shift_corrections` escrita y el asiento de `audit_log` cerrado. Convertir eso
 * en un `500` por no poder incrementar un contador le diria a quien corrigio que
 * no se guardo nada, y volveria a intentarlo.
 */
final readonly class RedisCorrectionMetrics implements CorrectionMetrics
{
    public const string MANUAL_CORRECTIONS_TOTAL = RedisScanMetrics::KEY_PREFIX.'manual_corrections_total';

    public function __construct(private Redis $redis) {}

    public function correctionRecorded(string $reasonCode): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::MANUAL_CORRECTIONS_TOTAL,
                'reason_code='.$reasonCode,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }
}
