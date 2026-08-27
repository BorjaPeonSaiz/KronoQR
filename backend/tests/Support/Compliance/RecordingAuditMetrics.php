<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Modules\Compliance\Application\Port\AuditMetrics;
use App\Modules\Compliance\Domain\ValueObject\AuditChainVerification;
use App\Modules\Compliance\Domain\ValueObject\AuditPartitionStatus;
use DateTimeImmutable;

/**
 * Contador de metricas en memoria.
 *
 * `failuresTotal` acumula igual que el `counter` de Prometheus: es lo que
 * permite afirmar en una prueba que `audit_chain_verification_failures_total`
 * **se incrementa** ante una rotura, que es lo que el §8.2 pide observar.
 */
final class RecordingAuditMetrics implements AuditMetrics
{
    public int $failuresTotal = 0;

    public ?AuditChainVerification $lastVerification = null;

    public ?AuditPartitionStatus $lastPartitionStatus = null;

    public function recordVerification(AuditChainVerification $result, DateTimeImmutable $at): void
    {
        $this->failuresTotal += $result->failureCount();
        $this->lastVerification = $result;
    }

    public function recordPartitionStatus(AuditPartitionStatus $status, DateTimeImmutable $at): void
    {
        $this->lastPartitionStatus = $status;
    }
}
