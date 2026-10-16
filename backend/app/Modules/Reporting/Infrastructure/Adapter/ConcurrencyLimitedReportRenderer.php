<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Exception\ReportRenderingBusy;
use App\Modules\Reporting\Application\Exception\ReportRenderingUnavailable;
use App\Modules\Reporting\Application\Port\PdfRenderingMetrics;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tope de composiciones de PDF simultaneas, delante del motor
 * ({@see BrowsershotReportRenderer}). Un `Redis::funnel` compartido por todos
 * los procesos PHP de la instalacion: cada hueco es un Chromium.
 *
 * - **Saturado** tras esperar `$waitSeconds`: {@see ReportRenderingBusy}, que el
 *   borde sirve como `503` con `Retry-After` y la salida en CSV.
 * - **Sin Redis**: {@see ReportRenderingUnavailable}, el mismo `503` que sin
 *   Chromium. Falla cerrado porque abrirlo seria quitar el tope justo cuando la
 *   instalacion esta peor; el CSV no depende de ninguno de los dos.
 *
 * El hueco se suelta al terminar la composicion, tambien si falla. Si el
 * proceso muere a mitad, Redis lo libera a los {@see self::SLOT_TTL_SECONDS}.
 */
final readonly class ConcurrencyLimitedReportRenderer implements ReportDocumentRenderer
{
    public const string FUNNEL = 'reporting:pdf-rendering';

    /**
     * Caducidad de un hueco abandonado. Por encima del tiempo maximo que
     * Browsershot deja a Chromium (60 s de serie): un hueco nunca caduca con
     * su composicion todavia viva.
     */
    public const int SLOT_TTL_SECONDS = 120;

    public function __construct(
        private ReportDocumentRenderer $engine,
        private Redis $redis,
        private LoggerInterface $logger,
        private PdfRenderingMetrics $metrics,
        private int $maxConcurrent,
        private int $waitSeconds,
        private string $funnel = self::FUNNEL,
    ) {}

    public function renderPdf(string $html, string $footerHtml, bool $alwaysTagged = false): string
    {
        $render = fn (): string => $this->engine->renderPdf($html, $footerHtml, $alwaysTagged);
        $busy = function (): never {
            $this->logger->warning('reporting.pdf_rendering_busy', [
                'max_concurrent' => $this->maxConcurrent,
                'wait_seconds' => $this->waitSeconds,
            ]);
            $this->metrics->rejected(PdfRenderingMetrics::REASON_BUSY);

            throw ReportRenderingBusy::allSlotsTaken($this->waitSeconds);
        };

        try {
            $bytes = $this->redis->connection()
                ->funnel($this->funnel)
                ->limit(max(1, $this->maxConcurrent))
                ->releaseAfter(self::SLOT_TTL_SECONDS)
                ->block(max(0, $this->waitSeconds))
                ->then($render, $busy);
        } catch (ReportRenderingBusy|ReportRenderingUnavailable $expected) {
            throw $expected;
        } catch (Throwable $redisFailure) {
            $this->logger->error('reporting.pdf_rendering_limiter_unavailable', [
                'exception' => $redisFailure::class,
            ]);
            $this->metrics->rejected(PdfRenderingMetrics::REASON_LIMITER);

            throw ReportRenderingUnavailable::limiterFailed($redisFailure);
        }

        \assert(\is_string($bytes));

        return $bytes;
    }
}
