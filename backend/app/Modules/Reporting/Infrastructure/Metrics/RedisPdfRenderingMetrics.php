<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\PdfRenderingMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * {@see PdfRenderingMetrics} con `HINCRBY`, como `report_exports_total`: dos
 * procesos PHP no pueden pisarse escribiendo el mismo contador.
 *
 * El silencio ante un fallo de Redis es deliberado: contar un rechazo nunca
 * puede cambiar la respuesta que ya se ha decidido. Y el motivo `limiter` es
 * precisamente Redis sin responder, asi que ese contador se pierde justo
 * cuando ocurre; lo que queda es la linea `reporting.pdf_rendering_limiter_unavailable`
 * del log.
 */
final readonly class RedisPdfRenderingMetrics implements PdfRenderingMetrics
{
    public const string PDF_RENDERING_REJECTED_TOTAL = RedisReportExportMetrics::KEY_PREFIX.'reporting_pdf_rendering_rejected_total';

    public function __construct(private Redis $redis) {}

    public function rejected(string $reason): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::PDF_RENDERING_REJECTED_TOTAL,
                'reason='.$reason,
                1,
            ]);
        } catch (Throwable) {
            // Ver el docblock.
        }
    }
}
