<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * El minimo de años que la ley de una jurisdiccion obliga a conservar el
 * registro horario (RL-02, RF-PD-07, ADR-057 enmienda del 10-10-2026).
 *
 * Es un dato legal del producto, no del cliente (regla dura 13): lo resuelve el
 * puerto `RetentionYearsFloor` desde el catalogo `compliance.retention_floors`
 * y llega al dominio ya resuelto (regla dura 14). El plazo que elige el cliente
 * es `compliance_profiles.retention_years`; esto es por debajo de donde no
 * puede bajarlo.
 *
 * **Nunca menor que 1.** Un suelo de cero no describe una ley mas laxa: apaga
 * la retencion, y el `CHECK` de positivos del esquema ya lo prohibe.
 */
final readonly class LegalRetentionFloor
{
    private function __construct(
        public Jurisdiction $jurisdiction,
        public int $years,
        /** `false` = la jurisdiccion no esta en el catalogo y rige `default`. */
        public bool $fromCatalog,
    ) {
        if ($years < 1) {
            throw new InvalidArgumentException('A legal retention floor is at least 1 year, got '.$years.'.');
        }
    }

    public static function fromCatalog(Jurisdiction $jurisdiction, int $years): self
    {
        return new self($jurisdiction, $years, true);
    }

    public static function byDefault(Jurisdiction $jurisdiction, int $years): self
    {
        return new self($jurisdiction, $years, false);
    }

    /** RL-02: el plazo del perfil cumple el suelo. Limite cerrado: 4 con suelo 4 cumple. */
    public function admits(int $retentionYears): bool
    {
        return $retentionYears >= $this->years;
    }
}
