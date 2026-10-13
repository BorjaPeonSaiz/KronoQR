<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\PurgeReviewLedger;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurgeReview;
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

    public function reviewOf(AuditedPurge $purge): ?int
    {
        // El SQL solo acota candidatas por el indice de accion; si una revision
        // cuenta lo decide el dominio, con la misma regla que la conciliacion
        // (`AuditedPurgeReview::reviews()`): una revision con el id en cadena o
        // anterior a la purga no la cuenta ninguno de los dos caminos.
        $rows = $this->connection->select(
            'SELECT a.id, a.occurred_at, a.payload::text AS payload FROM audit_log a '
            ."WHERE a.action = ? AND a.payload ->> 'reviewed_audit_entry_id' = ? ORDER BY a.id",
            [AuditAction::RetentionPurgeReviewed->value, (string) $purge->auditEntryId],
        );

        foreach ($rows as $raw) {
            $row = Row::of((object) $raw);
            $review = AuditedPurgeReview::fromEntry($row->int('id'), $row->instant('occurred_at'), $row->json('payload') ?? []);

            if ($review->reviews($purge)) {
                return $review->auditEntryId;
            }
        }

        return null;
    }
}
