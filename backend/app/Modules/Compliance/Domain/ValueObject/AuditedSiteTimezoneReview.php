<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un asiento `site.timezone_discrepancy_reviewed`, tal y como se leyo (RN-23,
 * ADR-056; bloque 2 de la 2.3.0).
 *
 * Dice que una persona dio por investigada una discrepancia de la zona del
 * centro: una fila de la traza `site_timezone_changes` sin su asiento firmado,
 * o un `site.updated` que cambio la zona sin firma. Como la revision de una
 * purga (`AuditedPurgeReview`), no borra nada: la discrepancia deja de contarse
 * como pendiente y se cuenta como revisada, con su propia alerta.
 */
final readonly class AuditedSiteTimezoneReview
{
    public function __construct(
        public int $auditEntryId,
        /** `reviewed_site_timezone_change_id`: la fila de la traza revisada. */
        public ?int $reviewedChangeId,
        /** `reviewed_audit_entry_id`: el `site.updated` revisado. */
        public ?int $reviewedAuditEntryId,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromEntry(int $auditEntryId, array $payload): self
    {
        $change = $payload['reviewed_site_timezone_change_id'] ?? null;
        $entry = $payload['reviewed_audit_entry_id'] ?? null;

        return new self($auditEntryId, \is_int($change) ? $change : null, \is_int($entry) ? $entry : null);
    }

    /**
     * Si revisa esa fila de la traza: la nombra y es posterior al ultimo
     * asiento que habia al cambiar. Una revision escrita de antemano, antes de
     * que la fila existiera, no revisa nada.
     */
    public function reviewsChange(RecordedSiteTimezoneChange $row): bool
    {
        return $this->reviewedChangeId === $row->id && $this->auditEntryId > $row->auditMark;
    }

    /** Si revisa ese asiento: lo nombra y es posterior a el en la cadena. */
    public function reviewsEntry(int $auditEntryId): bool
    {
        return $this->reviewedAuditEntryId === $auditEntryId && $this->auditEntryId > $auditEntryId;
    }
}
