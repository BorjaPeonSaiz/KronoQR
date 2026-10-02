<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\DataExportRepository;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use Closure;
use DateTimeImmutable;

/**
 * El repositorio de verdad, con un gancho que se ejecuta **justo antes del
 * primer `markPurged()`** (ADR-045, I1/F4).
 *
 * Sirve para intercalar dos pasadas de la purga de forma determinista: la
 * primera ya leyo sus filas y esta a punto de marcar una; en ese hueco corre la
 * segunda pasada entera. Es exactamente el caso del planificador y una ejecucion
 * a mano de `product:export-all --purge` a la vez.
 */
final class InterleavingDataExportRepository implements DataExportRepository
{
    private bool $fired = false;

    public function __construct(private DataExportRepository $inner, private Closure $beforeFirstMark) {}

    public function markPurged(int $id, DateTimeImmutable $purgedAt): bool
    {
        if (! $this->fired) {
            $this->fired = true;
            ($this->beforeFirstMark)();
        }

        return $this->inner->markPurged($id, $purgedAt);
    }

    public function create(string $uuid, DataExportOrigin $requestedVia, ?int $requestedByUserId, DateTimeImmutable $requestedAt): DataExport
    {
        return $this->inner->create($uuid, $requestedVia, $requestedByUserId, $requestedAt);
    }

    public function findByUuid(string $uuid): ?DataExport
    {
        return $this->inner->findByUuid($uuid);
    }

    public function inProgress(): ?DataExport
    {
        return $this->inner->inProgress();
    }

    public function recent(int $limit): array
    {
        return $this->inner->recent($limit);
    }

    public function markRunning(int $id, DateTimeImmutable $startedAt): void
    {
        $this->inner->markRunning($id, $startedAt);
    }

    public function markCompleted(
        int $id,
        DateTimeImmutable $completedAt,
        string $filePath,
        string $fileName,
        int $sizeBytes,
        string $sha256,
        array $rowCounts,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->inner->markCompleted($id, $completedAt, $filePath, $fileName, $sizeBytes, $sha256, $rowCounts, $expiresAt);
    }

    public function markFailed(int $id, DateTimeImmutable $failedAt, DataExportFailure $failureReason): void
    {
        $this->inner->markFailed($id, $failedAt, $failureReason);
    }

    public function failStale(DateTimeImmutable $staleBefore, DateTimeImmutable $now): int
    {
        return $this->inner->failStale($staleBefore, $now);
    }

    public function recordDownload(int $id, DateTimeImmutable $downloadedAt): void
    {
        $this->inner->recordDownload($id, $downloadedAt);
    }

    public function expired(DateTimeImmutable $now): array
    {
        return $this->inner->expired($now);
    }

    public function completedWithFile(): array
    {
        return $this->inner->completedWithFile();
    }
}
