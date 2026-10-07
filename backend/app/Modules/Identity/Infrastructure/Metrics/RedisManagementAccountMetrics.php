<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Metrics;

use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `kronoqr_management_account_changes_total{action,role}` en Redis (RF-ID-10),
 * con la forma `LabelledHash` del catalogo de `/metrics`: un HASH por serie y la
 * combinacion de etiquetas como campo.
 *
 * Las combinaciones sin valor las publica a cero el propio catalogo, para que
 * las alertas de seguridad que cuelgan de `increase()` vean el primer suceso.
 */
final readonly class RedisManagementAccountMetrics implements ManagementAccountMetrics
{
    /** Mismo prefijo que el resto de metricas: lo comprueba `MetricsCatalogueTest`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string CHANGES_TOTAL = self::KEY_PREFIX.'kronoqr_management_account_changes_total';

    public function __construct(private Redis $redis) {}

    public function changed(ManagementAccountChange $action, UserRole $role): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::CHANGES_TOTAL,
                'action='.$action->value.',role='.$role->value,
                1,
            ]);
        } catch (Throwable) {
            // Medir no puede deshacer una baja ni un restablecimiento que ya
            // estan confirmados: perder un contador es lo barato.
        }
    }
}
