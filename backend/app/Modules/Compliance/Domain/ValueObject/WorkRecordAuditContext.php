<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Lo que explica una ausencia en el registro, leido en la misma instantanea que
 * los tramos (ADR-057 §4): los asientos de purga del registro, los años de
 * `audit_log` purgados con su ancla (ADR-027) y, desde el bloque 2 de la 2.3.0,
 * la zona de cada centro con la de su ultimo asiento (RN-23).
 *
 * Viaja por el mismo iterable que los pares, y el primero: si se leyera aparte,
 * una purga confirmada entre las dos lecturas convertiria lo recien purgado en
 * un borrado.
 */
final readonly class WorkRecordAuditContext
{
    /**
     * @param  list<AuditedPurge>  $purges
     * @param  list<int>  $sealedAuditYears
     * @param  list<AuditedPurgeReview>  $reviews  Los `retention.purge_reviewed`, en la misma instantanea.
     * @param  list<AuditedSiteTimezone>  $sites  La zona de cada centro frente a su ultimo asiento `site.*` (RN-23), en la misma instantanea.
     */
    public function __construct(
        public array $purges = [],
        public array $sealedAuditYears = [],
        public array $reviews = [],
        public array $sites = [],
    ) {}
}
