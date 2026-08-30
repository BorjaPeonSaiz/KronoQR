<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\ReportExportMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `report_exports_total{format}` sobre Redis (doc 02 §8.2, RF-IN-04).
 *
 * **Redis y no el colector *textfile*, por coherencia y no por volumen.** Las
 * descargas de informes son unas cuantas al mes, asi que un fichero por
 * exportacion tampoco seria un problema de disco; lo que si seria un problema es
 * que dos procesos PHP reescribieran el mismo fichero a la vez. `HINCRBY` es
 * atomico y ya hay Redis en el stack. Mismo prefijo de clave que sus hermanas
 * —`RedisWorkedTimeMetrics` de este mismo modulo y `RedisScanMetrics` de
 * `Attendance`, nombrada asi y no como `{@see}` porque un `use` de otro modulo
 * es la frontera del §1.6 que Deptrac rechaza— para que el `SCAN` del endpoint
 * `/metrics` de la tarea 3.1 las encuentre todas juntas.
 *
 * **Medir no puede romper una descarga.** Si Redis no responde, el informe se
 * entrega igual: perder un punto de una serie es infinitamente mas barato que
 * dejar sin fichero a quien tiene que cerrar una nomina.
 */
final readonly class RedisReportExportMetrics implements ReportExportMetrics
{
    /** El mismo prefijo que el resto de las series del producto. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string REPORT_EXPORTS_TOTAL = self::KEY_PREFIX.'report_exports_total';

    public function __construct(private Redis $redis) {}

    public function exported(string $format): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::REPORT_EXPORTS_TOTAL,
                'format='.$format,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo. Ver el docblock.
        }
    }
}
