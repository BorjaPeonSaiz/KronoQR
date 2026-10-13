<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use App\Modules\Shared\Domain\Exception\InvalidJurisdiction;

/**
 * La jurisdiccion cuyo marco legal describe un perfil de cumplimiento
 * (RF-PD-07, RL-02, ADR-057 enmienda del 10-10-2026).
 *
 * ISO 3166-1 alfa-2 (`ES`) con subdivision ISO 3166-2 opcional (`ES-CN`). La
 * subdivision hereda el suelo legal de su pais: por eso existe
 * {@see self::country()}, que lee el pais igual que el `CHECK` del esquema
 * (`split_part(jurisdiction, '-', 1)`).
 */
final readonly class Jurisdiction
{
    /** Igual que el CHECK compliance_profiles_chk_jurisdiction_format. */
    public const string PATTERN = '/\A[A-Z]{2}(-[A-Z0-9]{1,3})?\z/';

    private function __construct(public string $code) {}

    /**
     * @throws InvalidJurisdiction No normaliza: 'es', ' ES' o 'ESP' se rechazan, como en el esquema.
     */
    public static function fromCode(string $code): self
    {
        if (preg_match(self::PATTERN, $code) !== 1) {
            throw InvalidJurisdiction::malformed($code);
        }

        return new self($code);
    }

    /** 'ES' de 'ES-CN'. */
    public function country(): string
    {
        return substr($this->code, 0, 2);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
