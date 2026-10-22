<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Adapter;

use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El avance de las exportaciones en curso, en la cache (**V5-PA-4**).
 *
 * La cache de serie de la instalacion (`resilient`: Redis con su circuito).
 * Cada anotacion **caduca sola** a los `REPORTING_EXPORT_STALE_AFTER`
 * segundos, el mismo plazo tras el que una exportacion sin terminar se da por
 * muerta: un avance que sobrevive a su exportacion no le sirve a nadie.
 *
 * ## Se traga sus fallos, y lo deja escrito
 *
 * Ver el puerto: una cache caida no convierte una generacion sana en fallida
 * ni una lista en un `503`. El fallo va al log con el `uuid` de la exportacion
 * y la clase de la excepcion, **nunca con su mensaje**, que podria llevar el
 * valor guardado (regla dura 21).
 */
final readonly class CacheReportExportProgressBoard implements ReportExportProgressBoard
{
    private const string KEY_PREFIX = 'reporting:report-export-progress:';

    public function __construct(
        private Repository $cache,
        private LoggerInterface $logger,
        /** Ya resuelto por quien construye (regla dura 14). */
        private int $ttlSeconds,
    ) {}

    public function record(string $uuid, ReportExportProgress $progress): void
    {
        try {
            $this->cache->put(self::KEY_PREFIX.$uuid, $progress->toArray(), max(1, $this->ttlSeconds));
        } catch (Throwable $failure) {
            $this->unavailable('record', $uuid, $failure);
        }
    }

    public function of(array $uuids): array
    {
        $found = [];

        foreach ($uuids as $uuid) {
            try {
                $stored = $this->cache->get(self::KEY_PREFIX.$uuid);
            } catch (Throwable $failure) {
                $this->unavailable('read', $uuid, $failure);

                // Si la cache no contesta para una, no contestara para las
                // siguientes: se deja de preguntar.
                return $found;
            }

            $progress = \is_array($stored) ? ReportExportProgress::fromArray($stored) : null;

            if ($progress instanceof ReportExportProgress) {
                $found[$uuid] = $progress;
            }
        }

        return $found;
    }

    public function forget(string $uuid): void
    {
        try {
            $this->cache->forget(self::KEY_PREFIX.$uuid);
        } catch (Throwable $failure) {
            $this->unavailable('forget', $uuid, $failure);
        }
    }

    private function unavailable(string $operation, string $uuid, Throwable $failure): void
    {
        $this->logger->warning('reporting.report_export_progress_unavailable', [
            'operation' => $operation,
            'report_export_uuid' => $uuid,
            'failure' => $failure::class,
        ]);
    }
}
