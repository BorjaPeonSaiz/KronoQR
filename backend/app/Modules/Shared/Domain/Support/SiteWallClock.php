<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * La hora que marcaba el reloj del centro en un instante: **la unica
 * conversion UTC → zona del centro** de `Reporting` y de `Kiosk` (regla dura 3,
 * ADR-040).
 *
 * Lo que se almacena es UTC y lo que lee una persona es la hora que vivio: un
 * informe sellado a las 05:12 cuando en el hotel eran las 07:12 parece de otro
 * sistema. La conversion se hace solo al presentar, y se hace aqui.
 *
 * ## Por que una sola
 *
 * Estaba copiada en el CSV del registro propio, en los tres cuadernos de
 * exportacion (periodo, adopcion, registro propio) y en el recurso de jornadas.
 * Cinco copias de una linea no fallan por separado: fallan cuando una cambia de
 * formato y las demas no, y el mismo tramo sale a una hora en el PDF y a otra
 * en el CSV.
 *
 * Vive en `Shared/Domain/Support`, junto a `UtcInstant`, desde que la consola
 * de salud de los quioscos tambien escribe la hora del centro: `Kiosk` no puede
 * usar una clase de `Reporting` (Deptrac), y una copia propia volveria a ser la
 * sexta. Es el criterio de admision de ADR-021: la necesitan dos modulos, no es
 * una regla de negocio de ninguno y no depende de nada mas que de PHP.
 * `SingleTimeFormatterTest` vigila que nadie fuera de aqui convierta a la zona
 * del centro.
 *
 * ## PHP y PostgreSQL tienen que decir lo mismo
 *
 * La exportacion legal (`Compliance`) convierte en PostgreSQL con
 * `AT TIME ZONE`, que usa la base tz del servidor de base de datos; esto usa la
 * de PHP. Son dos copias de la misma base que se actualizan por separado, y si
 * se desfasan el registro legal y el historico del empleado dan horas
 * distintas para el mismo tramo. `SiteWallClockMatchesPostgresTest` lo
 * comprueba con todas las zonas que acepta el producto y los cambios de hora de
 * marzo y de octubre.
 */
final readonly class SiteWallClock
{
    private DateTimeZone $zone;

    public function __construct(string $timeZone)
    {
        $this->zone = new DateTimeZone($timeZone);
    }

    /**
     * `2026-03-29 03:00`: fecha y minuto de pared, la forma de las tablas que
     * lee una persona. La zona la escribe quien compone la tabla, al lado.
     */
    public function minute(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format('Y-m-d H:i');
    }

    /**
     * `UTC+02:00`: el desfase respecto a UTC que tenia el reloj del centro en
     * ese instante. Es lo que hace inequivoca una hora de pared: en la madrugada
     * del cambio de hora de octubre las `02:30` pasan dos veces, una a
     * `UTC+02:00` y otra a `UTC+01:00` (R1-RV-01).
     *
     * El desfase y no la abreviatura: `CEST` existe para Madrid, pero para
     * muchas zonas la base tz no tiene abreviatura y PHP escribe `-03`.
     *
     * **Con el prefijo `UTC`**, que no es adorno: un `+02:00` suelto es, para
     * una hoja de calculo, el principio de una formula, y el dialecto CSV lo
     * neutraliza anteponiendo un apostrofo. `UTC+02:00` se lee igual en el CSV y
     * en el PDF y no hay que neutralizar nada.
     */
    public function offset(DateTimeImmutable $instant): string
    {
        return 'UTC'.$instant->setTimezone($this->zone)->format('P');
    }

    /**
     * `2026-03-29T03:00:00.000000+02:00`: el mismo instante con el
     * desplazamiento explicito, el esquema `LocalTimestamp` del contrato. No
     * sustituye al de UTC, viaja ademas.
     */
    public function withOffset(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * `09/09/2026 07:14:03 UTC+02:00`: fecha y hora de pared con el patron que
     * elige quien escribe —el de su idioma, que es texto traducido— y **siempre**
     * el desfase detras, con la forma de {@see self::offset()}. Sin el, la
     * madrugada del cambio de hora de octubre da dos instantes con la misma
     * hora escrita.
     *
     * El patron no lleva zona: la pone este metodo. Si la trajera, saldria dos
     * veces.
     */
    public function stamp(DateTimeImmutable $instant, string $pattern): string
    {
        return $instant->setTimezone($this->zone)->format($pattern).' '.$this->offset($instant);
    }
}
