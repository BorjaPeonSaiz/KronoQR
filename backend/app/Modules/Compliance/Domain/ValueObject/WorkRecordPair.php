<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un identificador de tramo con lo que hay de el a cada lado: la fila de
 * `shift_entries` y lo que dicen sus asientos de `audit_log` (ADR-057 §4).
 *
 * Uno de los dos puede faltar, y que falte es justamente lo que se busca: sin
 * asiento es un tramo inventado, sin fila es un tramo borrado. Lo que explica
 * una ausencia —las purgas— no viaja aqui sino en {@see WorkRecordAuditContext},
 * leido en la misma instantanea.
 */
final readonly class WorkRecordPair
{
    public function __construct(
        public string $shiftEntryUuid,
        public ?RecordedShiftEntry $recorded,
        public ?AuditedShiftEntry $audited,
    ) {}
}
