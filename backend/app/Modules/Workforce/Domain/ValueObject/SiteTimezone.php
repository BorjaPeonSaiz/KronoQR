<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use DateTimeZone;

/**
 * La zona horaria de un centro, comprobada contra la base de datos de husos.
 *
 * **Esta clase existe por RN-05.** La jornada es «la fecha civil, en la zona del
 * centro, del `clocked_in_at` del tramo que la abre». Si aqui entra
 * `Europe/Madird`, nada falla de forma visible: el sistema cae a UTC en algun
 * punto y los turnos de noche se atribuyen al dia equivocado durante meses,
 * hasta que alguien cuadra una nomina. Un objeto de valor que no se puede
 * construir mal convierte ese fallo silencioso en un error inmediato.
 *
 * **Identificador IANA, nunca un desfase.** `+02:00` no sabe de cambios de hora
 * y produciria una hora mal calculada dos veces al ano (RN-09, regla dura 3).
 */
final readonly class SiteTimezone
{
    private function __construct(public string $identifier) {}

    /**
     * @throws UnknownTimezone
     */
    public static function fromString(string $identifier): self
    {
        $normalized = trim($identifier);

        // `listIdentifiers` incluye los alias historicos; se comprueba contra la
        // lista completa para no rechazar una zona valida que un cliente ya
        // tenga configurada.
        if ($normalized === '' || ! \in_array($normalized, DateTimeZone::listIdentifiers(), true)) {
            throw UnknownTimezone::forIdentifier($identifier);
        }

        return new self($normalized);
    }

    public function toDateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->identifier);
    }

    public function equals(self $other): bool
    {
        return $this->identifier === $other->identifier;
    }
}
