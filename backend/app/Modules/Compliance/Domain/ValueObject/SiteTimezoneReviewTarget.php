<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Que discrepancia de la zona del centro se da por revisada (RN-23, ADR-056;
 * bloque 2 de la 2.3.0): una fila de la traza `site_timezone_changes`
 * (`change:<id>`) o un asiento `site.updated` sin firma (`audit:<id>`).
 *
 * Con prefijo y no un numero a secas porque los dos son enteros positivos y
 * podrian coincidir: revisar el asiento 12 cuando se queria la fila 12 seria
 * una revision firmada de algo que nadie investigo.
 */
final readonly class SiteTimezoneReviewTarget
{
    private const string CHANGE = 'change';

    private const string AUDIT = 'audit';

    private function __construct(
        public bool $isChange,
        public int $id,
    ) {}

    /** `null` si no es `change:<id>` ni `audit:<id>` con un entero positivo escrito tal cual. */
    public static function parse(string $value): ?self
    {
        if (preg_match('/^(change|audit):([1-9]\d{0,17})$/', trim($value), $match) !== 1) {
            return null;
        }

        return new self($match[1] === self::CHANGE, (int) $match[2]);
    }

    public static function change(int $id): self
    {
        return new self(true, $id);
    }

    public static function auditEntry(int $id): self
    {
        return new self(false, $id);
    }

    /** Si es esa discrepancia. */
    public function matches(WorkRecordDiscrepancy $discrepancy): bool
    {
        return $this->isChange
            ? $discrepancy->siteTimezoneChangeId === $this->id
            : $discrepancy->siteTimezoneChangeId === null && $discrepancy->auditEntryId === $this->id;
    }

    public function label(): string
    {
        return ($this->isChange ? self::CHANGE : self::AUDIT).':'.$this->id;
    }
}
