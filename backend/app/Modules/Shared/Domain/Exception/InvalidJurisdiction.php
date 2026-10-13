<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use RuntimeException;

/**
 * El codigo no tiene la forma ISO 3166 que el producto lee como jurisdiccion
 * (RF-PD-07, RL-02, ADR-057 enmienda del 10-10-2026, decision D5 del bloque 5).
 *
 * **No normaliza a proposito.** `es`, ` ES` o `ESP` no son `ES` mal escrito:
 * son una fila que el `CHECK` `compliance_profiles_chk_jurisdiction_format`
 * rechaza, y aceptarlos aqui haria que PHP y SQL leyeran el pais distinto, que
 * es exactamente como se esquivaria el suelo legal español.
 *
 * El codigo no es dato personal: puede ir al mensaje y al log.
 */
final class InvalidJurisdiction extends RuntimeException
{
    public static function malformed(string $code): self
    {
        return new self(
            'Jurisdiction "'.$code.'" is not an ISO 3166-1 alpha-2 code with an optional ISO 3166-2 '
            .'subdivision (ES, ES-CN).'
        );
    }
}
