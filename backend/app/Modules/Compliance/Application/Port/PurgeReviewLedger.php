<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;

/**
 * Lo que la revision de una purga necesita leer de `audit_log` (ADR-057
 * enmienda del 10-10-2026 §6). Sin tabla nueva: la marca de «revisado» vive en
 * la propia cadena, que ya es solo-append (regla dura 6).
 */
interface PurgeReviewLedger
{
    /**
     * El asiento, solo si es un `retention.purge_executed` de alcance
     * `work_records`; `null` para cualquier otro asiento o un id que no existe.
     */
    public function purge(int $auditEntryId): ?AuditedPurge;

    /** El id del `retention.purge_reviewed` que ya revisa esa purga, si existe. */
    public function reviewOf(int $purgeAuditEntryId): ?int;
}
