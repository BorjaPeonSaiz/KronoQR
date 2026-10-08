<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un tramo cuyo registro no cuadra con su auditoria, o un asiento de purga que
 * no se puede creer (ADR-057 §4).
 *
 * **Lleva nombres de campo, nunca valores.** Que `clocked_in_at` no coincide es
 * lo que hay que investigar; a que hora entro esa persona es un dato personal y
 * se mira en la base, con el runbook delante, no en un log que viaja en el
 * paquete de diagnostico (regla dura 21). Por lo mismo el tramo se identifica
 * por su `uuid` y el asiento por su `id`: los dos bastan para encontrar el resto.
 */
final readonly class WorkRecordDiscrepancy
{
    /**
     * @param  string|null  $shiftEntryUuid  Nulo solo en un `purge_out_of_bounds`, que no es de ningun tramo.
     * @param  list<string>  $fields  Los campos que no coinciden. Vacia cuando falta un lado entero.
     */
    public function __construct(
        public ?string $shiftEntryUuid,
        public WorkRecordDiscrepancyKind $kind,
        public array $fields = [],
        public ?int $auditEntryId = null,
    ) {}

    public function describe(): string
    {
        $line = $this->shiftEntryUuid === null
            ? 'audit_log #'.$this->auditEntryId.' · '.$this->kind->value
            : 'shift_entries '.$this->shiftEntryUuid.' · '.$this->kind->value;

        if ($this->fields !== []) {
            $line .= ' · '.implode(', ', $this->fields);
        }

        if ($this->shiftEntryUuid !== null && $this->auditEntryId !== null) {
            $line .= ' · audit_log #'.$this->auditEntryId;
        }

        return $line;
    }
}
