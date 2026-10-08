<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Lo que explica una ausencia en el registro, leido en la misma instantanea que
 * los tramos (ADR-057 §4): los asientos de purga del registro y los años de
 * `audit_log` purgados con su ancla (ADR-027).
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
     */
    public function __construct(
        public array $purges = [],
        public array $sealedAuditYears = [],
    ) {}
}
