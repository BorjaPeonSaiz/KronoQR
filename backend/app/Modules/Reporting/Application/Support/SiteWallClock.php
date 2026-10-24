<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * La hora que marcaba el reloj del centro en un instante: **la unica
 * conversion UTC → zona del centro de `Reporting`** (regla dura 3, ADR-040).
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
     * `2026-03-29T03:00:00.000000+02:00`: el mismo instante con el
     * desplazamiento explicito, el esquema `LocalTimestamp` del contrato. No
     * sustituye al de UTC, viaja ademas.
     */
    public function withOffset(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format('Y-m-d\TH:i:s.uP');
    }
}
