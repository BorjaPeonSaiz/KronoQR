<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Metrics;

use App\Modules\Product\Application\Port\SettingsMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `installation_setting_changes_total{affects_worked_hours}` sobre Redis
 * (doc 02 §8.2).
 *
 * ## Por que Redis y no el colector *textfile*
 *
 * Mismo criterio que `RedisIncidentResolutionMetrics` y que las metricas de
 * correccion: esto es un **contador acumulativo** que se incrementa dentro de una
 * peticion HTTP, no un gauge que una tarea programada pueda recalcular leyendo la
 * tabla. Reconstruirlo despues no se puede —cuantas veces se cambio un umbral el
 * mes pasado no esta en `installation_settings`, que solo guarda el valor de
 * hoy—, y un fichero reescrito en cada `PATCH` seria una escritura de disco por
 * peticion y una carrera entre procesos PHP. `HINCRBY` es atomico.
 *
 * ## El prefijo se repite y no se importa
 *
 * `kronoqr:metrics:` esta escrito aqui igual que en las otras clases que
 * publican por Redis. Importar la constante de otro modulo seria una arista que
 * el §1.6 no concede, y un prefijo distinto dejaria la serie fuera del `SCAN` con
 * el que el endpoint `/metrics` las recoge todas.
 *
 * ## Contar no puede romper un cambio ya guardado
 *
 * Cuando se llega aqui la transaccion esta confirmada y el asiento de
 * `audit_log` escrito. Convertir eso en un `500` por no poder incrementar un
 * contador le diria a quien guardo que no se guardo nada, y volveria a
 * intentarlo.
 */
final readonly class RedisSettingsMetrics implements SettingsMetrics
{
    /** Prefijo comun para que el endpoint `/metrics` encuentre todas las series con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string CHANGES_TOTAL = self::KEY_PREFIX.'installation_setting_changes_total';

    public function __construct(private Redis $redis) {}

    public function settingsChanged(bool $affectsWorkedHours, int $changes): void
    {
        if ($changes < 1) {
            return;
        }

        try {
            // La etiqueta va en el nombre de la clave, como en el resto de las
            // series por Redis del producto: el endpoint `/metrics` la reparte.
            $this->redis->connection()->command('INCRBY', [
                self::CHANGES_TOTAL.':affects_worked_hours='.($affectsWorkedHours ? 'true' : 'false'),
                $changes,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }
}
