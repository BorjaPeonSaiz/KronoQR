<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * El comienzo de una fecha civil `YYYY-MM-DD` en una zona: la unica forma en el
 * dominio de convertir una fecha escrita en un instante (regla dura 2).
 *
 * ## Por que existe
 *
 * El constructor de `DateTimeImmutable` con la fecha seguida de `' 00:00:00'`
 * hace lo mismo cuando la fecha es buena, pero su analizador acepta cualquier
 * texto: si la cadena llega vacia o mal compuesta, `'today'`, `' 00:00:00'` o
 * `'+1 day'` se resuelven **contra el reloj del sistema** y el dominio lee la
 * hora sin que nada falle. `createFromFormat('!Y-m-d', …)` solo admite una
 * fecha, y el `!` pone a cero todo lo que el formato no nombra en vez de
 * tomarlo del momento actual. `DomainPurityTest` y Semgrep rechazan en el
 * dominio las otras formas.
 *
 * **Comprueba la vuelta**: `createFromFormat` acepta el 31 de febrero y lo
 * lleva a marzo, asi que una fecha que no se escribe igual al formatearla no
 * era una fecha.
 *
 * Donde la medianoche no existe —el salto de hora de `America/Santiago` es a
 * las 00:00—, PHP la lleva a la primera hora que si existe, igual que hacia el
 * constructor (RN-09). El dia sigue siendo el mismo.
 *
 * Vive en `Shared/Domain` por el criterio de ADR-021: lo usan Attendance,
 * Compliance y Reporting, y no es una regla de negocio de ninguno.
 */
final readonly class CivilDate
{
    private function __construct() {}

    /**
     * @throws InvalidArgumentException si `$isoDate` no es una fecha `YYYY-MM-DD` del calendario
     */
    public static function midnight(string $isoDate, DateTimeZone $zone): DateTimeImmutable
    {
        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, $zone);

        if (! $midnight instanceof DateTimeImmutable || $midnight->format('Y-m-d') !== $isoDate) {
            throw new InvalidArgumentException('No es una fecha YYYY-MM-DD del calendario: «'.$isoDate.'».');
        }

        return $midnight;
    }
}
