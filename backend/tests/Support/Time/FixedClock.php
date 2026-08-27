<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reloj detenido en un instante concreto (ADR-021, regla dura 2).
 *
 * Es la segunda implementacion del puerto `Clock` que justifica que el puerto
 * exista: sin ella no se puede probar que la particion del año siguiente se crea
 * **en noviembre** sin esperar a noviembre, ni que una entrada del 31 de
 * diciembre a las 23:59:59 UTC cae en el año que le corresponde.
 *
 * Vive en `tests/` y no en `app/Modules/`: `CoreBoundariesTest` exige una sola
 * implementacion del puerto en el arbol de modulos, y un doble de pruebas no es
 * una implementacion del producto.
 */
final class FixedClock implements Clock
{
    private function __construct(private DateTimeImmutable $instant) {}

    /**
     * `$wallClock` se interpreta en UTC, que es lo unico que el puerto devuelve
     * (regla dura 3).
     */
    public static function at(string $wallClock): self
    {
        return new self(new DateTimeImmutable($wallClock, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
