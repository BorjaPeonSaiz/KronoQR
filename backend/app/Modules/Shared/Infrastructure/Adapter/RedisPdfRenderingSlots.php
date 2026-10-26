<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsBusy;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsUnavailable;
use Closure;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Psr\Log\LoggerInterface;
use RedisException;
use Throwable;

/**
 * {@see PdfRenderingSlots} con un `Redis::funnel` compartido por todos los
 * procesos PHP de la instalacion: cada hueco es un Chromium.
 *
 * - **Saturado** tras esperar: {@see PdfRenderingSlotsBusy}.
 * - **Sin Redis**: {@see PdfRenderingSlotsUnavailable}. Falla cerrado porque
 *   abrirlo seria quitar el tope justo cuando la instalacion esta peor.
 *
 * El hueco se suelta al terminar la composicion, tambien si falla. Si el
 * proceso muere a mitad, Redis lo libera a los {@see self::SLOT_TTL_SECONDS}.
 *
 * **La clave del embudo es la de siempre** (`reporting:pdf-rendering`), aunque
 * ahora la compartan las tarjetas y la hoja de instrucciones: durante una
 * actualizacion conviven procesos de la version anterior y de la nueva, y con
 * dos claves cada version tendria su propio tope.
 */
final readonly class RedisPdfRenderingSlots implements PdfRenderingSlots
{
    public const string FUNNEL = 'reporting:pdf-rendering';

    /**
     * Caducidad de un hueco abandonado. Por encima del tiempo maximo que
     * Browsershot deja a Chromium (60 s de serie): un hueco nunca caduca con
     * su composicion todavia viva.
     */
    public const int SLOT_TTL_SECONDS = 120;

    public function __construct(
        private Redis $redis,
        private LoggerInterface $logger,
        private PdfRenderingMetrics $metrics,
        private int $maxConcurrent,
        private string $funnel = self::FUNNEL,
    ) {}

    public function compose(Closure $render, int $waitSeconds): string
    {
        $busy = function () use ($waitSeconds): never {
            $this->logger->warning('reporting.pdf_rendering_busy', [
                'max_concurrent' => $this->maxConcurrent,
                'wait_seconds' => $waitSeconds,
            ]);
            $this->metrics->rejected(PdfRenderingMetrics::REASON_BUSY);

            throw PdfRenderingSlotsBusy::allSlotsTaken($waitSeconds);
        };

        try {
            $bytes = $this->redis->connection()
                ->funnel($this->funnel)
                ->limit(max(1, $this->maxConcurrent))
                ->releaseAfter(self::SLOT_TTL_SECONDS)
                ->block(max(0, $waitSeconds))
                ->then($render, $busy);
        } catch (PdfRenderingSlotsBusy $expected) {
            throw $expected;
        } catch (Throwable $failure) {
            if (! $this->isLimiterFailure($failure)) {
                // Un fallo de la composicion, no del tope: sale tal cual, con
                // el hueco ya suelto, para que quien compone lo traduzca.
                throw $failure;
            }

            $this->logger->error('reporting.pdf_rendering_limiter_unavailable', [
                'exception' => $failure::class,
            ]);
            $this->metrics->rejected(PdfRenderingMetrics::REASON_LIMITER);

            throw PdfRenderingSlotsUnavailable::limiterFailed($failure);
        }

        \assert(\is_string($bytes));

        return $bytes;
    }

    /**
     * El embudo y la composicion comparten el `try`: hay que distinguir quien
     * fallo. Lo del embudo son errores de Redis (`RedisException` de phpredis,
     * el cliente de la instalacion, o el `LimiterTimeoutException` que no
     * deberia llegar porque hay `$busy`); lo demas es de la composicion.
     */
    private function isLimiterFailure(Throwable $failure): bool
    {
        return $failure instanceof RedisException
            || $failure instanceof LimiterTimeoutException;
    }
}
