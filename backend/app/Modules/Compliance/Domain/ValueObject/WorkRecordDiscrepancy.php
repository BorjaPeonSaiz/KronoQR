<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use InvalidArgumentException;

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
    ) {
        // Cada tipo señala lo suyo, y solo lo suyo: un hallazgo sin a quien
        // señalar se escribiria `audit_log # · …` y el runbook no tendria por
        // donde empezar (V1-AR-4). Tramo, centro y asiento: exigido (`true`),
        // prohibido (`false`) u opcional (`null`).
        [$entry, $site, $audit] = self::shapeOf($kind);

        if (! self::points($entry, $shiftEntryUuid)
            || ! self::points($site, $siteId)
            || ! self::points($audit, $auditEntryId)
            || ! self::points($site ? null : false, $siteTimezoneChangeId)
            || $shiftEntryUuid === '') {
            throw new InvalidArgumentException('Una discrepancia '.$kind->value.' no señala lo que su tipo exige.');
        }
    }

    /**
     * Tramo, centro y asiento de cada tipo.
     *
     * @return array{bool, bool, bool|null}
     */
    private static function shapeOf(WorkRecordDiscrepancyKind $kind): array
    {
        return match ($kind) {
            WorkRecordDiscrepancyKind::EntryWithoutAudit => [true, false, false],
            WorkRecordDiscrepancyKind::EntryDiffersFromAudit,
            WorkRecordDiscrepancyKind::RetiredWithoutCorrection,
            WorkRecordDiscrepancyKind::AuditWithoutEntry => [true, false, true],
            WorkRecordDiscrepancyKind::PurgeOutOfBounds => [false, false, true],
            WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit => [false, true, null],
        };
    }

    private static function points(?bool $required, string|int|null $value): bool
    {
        return $required === null || $required === ($value !== null);
    }

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
