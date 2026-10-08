<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Un asiento `retention.purge_executed` del registro horario, tal y como se
 * leyo (RL-02, ADR-057 §4).
 *
 * Es lo unico que explica que un tramo auditado ya no este en `shift_entries`.
 * Y precisamente por eso **no se cree sin comprobarlo**: la aplicacion tiene
 * `INSERT` sobre `audit_log`, la cadena no lleva secreto, y un asiento de purga
 * con un corte en el futuro taparia cualquier borrado. Lo que se exige esta en
 * {@see WorkRecordPurgeBoundary}.
 */
final readonly class AuditedPurge
{
    public function __construct(
        public int $auditEntryId,
        public DateTimeImmutable $occurredAt,
        /** `cutoff_date` tal cual: se valida, no se supone. */
        public ?string $cutoffDate,
        public ?int $retentionYears,
        public ?int $siteId,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromEntry(int $auditEntryId, DateTimeImmutable $occurredAt, array $payload): self
    {
        $cutoff = $payload['cutoff_date'] ?? null;
        $years = $payload['retention_years'] ?? null;
        $site = $payload['site_id'] ?? null;

        return new self(
            $auditEntryId,
            $occurredAt,
            \is_string($cutoff) ? $cutoff : null,
            \is_int($years) ? $years : null,
            \is_int($site) ? $site : null,
        );
    }
}
