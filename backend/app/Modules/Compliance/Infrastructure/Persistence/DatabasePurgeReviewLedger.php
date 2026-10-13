<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\PurgeReviewLedger;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * Los asientos de purga y de revision, leidos de `audit_log` por `id` y accion
 * (ADR-057 enmienda del 10-10-2026 §6). Solo lee: el asiento de la revision lo
 * escribe `RecordAuditEntry`, dentro del candado de la cadena.
 */
final readonly class DatabasePurgeReviewLedger implements PurgeReviewLedger
{
    public function __construct(private ConnectionInterface $connection) {}

    public function purge(int $auditEntryId): ?AuditedPurge
    {
        $raw = $this->connection->selectOne(
            'SELECT a.id, a.occurred_at, a.payload::text AS payload FROM audit_log a '
            ."WHERE a.id = ? AND a.action = ? AND a.payload ->> 'scope' = ?",
            [$auditEntryId, AuditAction::RetentionPurgeExecuted->value, RetentionScope::WorkRecords->value],
        );

        if ($raw === null) {
            return null;
        }

        $row = Row::of((object) $raw);

        return AuditedPurge::fromEntry($row->int('id'), $row->instant('occurred_at'), $row->json('payload') ?? []);
    }

    public function reviewOf(int $purgeAuditEntryId): ?int
    {
        // `->>` da texto: se compara con el id como texto para no depender de
        // que el payload lo guarde como numero o no. Solo cuenta una revision
        // posterior a la purga, como en la conciliacion.
        $id = $this->connection->scalar(
            'SELECT min(a.id) FROM audit_log a '
            ."WHERE a.action = ? AND a.payload ->> 'reviewed_audit_entry_id' = ? AND a.id > ?",
            [AuditAction::RetentionPurgeReviewed->value, (string) $purgeAuditEntryId, $purgeAuditEntryId],
        );

        return is_numeric($id) ? (int) $id : null;
    }
}
