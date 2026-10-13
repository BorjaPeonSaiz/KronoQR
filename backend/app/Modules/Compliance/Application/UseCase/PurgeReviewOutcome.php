<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

/**
 * La revision ya escrita: el asiento `retention.purge_reviewed` y lo que
 * explica (ADR-057 enmienda del 10-10-2026 §6).
 */
final readonly class PurgeReviewOutcome
{
    /**
     * @param  list<string>  $violations  Lo que el asiento revisado incumple, por nombre de campo.
     */
    public function __construct(
        public int $purgeAuditEntryId,
        public int $reviewAuditEntryId,
        public array $violations,
        public bool $explainsDeletions,
        public ?string $cutoffDate,
        public ?int $siteId,
        public int $floorYears,
    ) {}
}
