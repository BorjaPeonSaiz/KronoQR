<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Un asiento `retention.purge_reviewed`, tal y como se leyo (RL-04, RS-07,
 * ADR-057 enmienda del 10-10-2026 §6).
 *
 * Dice que una persona dio por investigada una purga no admisible. No se cree
 * sin mas: {@see WorkRecordPurgeBoundary} solo lo aplica si apunta a una purga
 * anterior a el en la cadena.
 */
final readonly class AuditedPurgeReview
{
    public function __construct(
        public int $auditEntryId,
        public DateTimeImmutable $occurredAt,
        /** `reviewed_audit_entry_id` tal cual: `null` si no es un entero. */
        public ?int $reviewedAuditEntryId,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromEntry(int $auditEntryId, DateTimeImmutable $occurredAt, array $payload): self
    {
        $reviewed = $payload['reviewed_audit_entry_id'] ?? null;

        return new self($auditEntryId, $occurredAt, \is_int($reviewed) ? $reviewed : null);
    }

    /** Si revisa ese asiento de purga: lo nombra y es posterior a el en la cadena. */
    public function reviews(AuditedPurge $purge): bool
    {
        return $this->reviewedAuditEntryId === $purge->auditEntryId
            && $this->auditEntryId > $purge->auditEntryId;
    }
}
