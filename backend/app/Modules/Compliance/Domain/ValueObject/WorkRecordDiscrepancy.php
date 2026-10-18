<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Un tramo cuyo registro no cuadra con su auditoria, un asiento de purga que
 * no se puede creer, o un centro cuya zona no es la de su ultimo asiento
 * (ADR-057 §4; RN-23).
 *
 * **Lleva nombres de campo, nunca valores.** Que `clocked_in_at` no coincide es
 * lo que hay que investigar; a que hora entro esa persona es un dato personal y
 * se mira en la base, con el runbook delante, no en un log que viaja en el
 * paquete de diagnostico (regla dura 21). Por lo mismo el tramo se identifica
 * por su `uuid`, el centro por su `id` y el asiento por su `id`: bastan para
 * encontrar el resto.
 */
final readonly class WorkRecordDiscrepancy
{
    /**
     * @param  string|null  $shiftEntryUuid  Nulo en un `purge_out_of_bounds` y en un `site_timezone_differs_from_audit`, que no son de ningun tramo.
     * @param  list<string>  $fields  Los campos que no coinciden. Vacia cuando falta un lado entero.
     * @param  int|null  $siteId  Solo en un `site_timezone_differs_from_audit`: el centro.
     */
    public function __construct(
        public ?string $shiftEntryUuid,
        public WorkRecordDiscrepancyKind $kind,
        public array $fields = [],
        public ?int $auditEntryId = null,
        public ?int $siteId = null,
        /** Solo en la de la traza: la fila de `site_timezone_changes`. */
        public ?int $siteTimezoneChangeId = null,
    ) {}

    public function describe(): string
    {
        $line = match (true) {
            $this->shiftEntryUuid !== null => 'shift_entries '.$this->shiftEntryUuid.' · '.$this->kind->value,
            $this->siteId !== null => 'sites #'.$this->siteId.' · '.$this->kind->value,
            default => 'audit_log #'.$this->auditEntryId.' · '.$this->kind->value,
        };

        if ($this->fields !== []) {
            $line .= ' · '.implode(', ', $this->fields);
        }

        if ($this->siteTimezoneChangeId !== null) {
            $line .= ' #'.$this->siteTimezoneChangeId;
        }

        if (($this->shiftEntryUuid !== null || $this->siteId !== null) && $this->auditEntryId !== null) {
            $line .= ' · audit_log #'.$this->auditEntryId;
        }

        return $line;
    }
}
