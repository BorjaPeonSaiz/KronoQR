<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un identificador de tramo con lo que hay de el a cada lado: la fila de
 * `shift_entries` y el ultimo asiento de `audit_log` (ADR-057 §4).
 *
 * Uno de los dos puede faltar, y que falte es justamente lo que se busca: sin
 * asiento es un tramo inventado, sin fila es un tramo borrado.
 *
 * **Y las dos purgas que explican una ausencia, leidas en la misma
 * instantanea.** Si el corte de la ultima purga se leyera antes que el registro
 * y una purga confirmara entre las dos lecturas, lo recien purgado saldria como
 * borrado. Por eso viajan con el par y no se preguntan aparte.
 */
final readonly class WorkRecordPair
{
    /**
     * @param  string|null  $purgedThrough  `cutoff_date` (`YYYY-MM-DD`) de la ultima purga auditada del registro (RL-02).
     * @param  list<int>  $sealedAuditYears  Años de `audit_log` purgados dejando su ancla (ADR-027).
     */
    public function __construct(
        public string $shiftEntryUuid,
        public ?RecordedShiftEntry $recorded,
        public ?AuditedShiftEntry $audited,
        public ?string $purgedThrough = null,
        public array $sealedAuditYears = [],
    ) {}
}
