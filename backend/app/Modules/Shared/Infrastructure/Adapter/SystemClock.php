<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reloj del sistema: la unica implementacion de produccion de {@see Clock}.
 *
 * Es el unico punto de todo el backend autorizado a preguntar la hora al
 * sistema operativo. Si aparece una segunda implementacion de este adaptador,
 * ADR-021 ha dejado de cumplirse: la prueba de arquitectura de la tarea 0.3
 * comprueba que solo existe una declaracion del puerto y una del adaptador.
 */
final class SystemClock implements Clock
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        // Zona explicita, no la del proceso: APP_TIMEZONE=UTC es la
        // configuracion esperada, pero el registro legal no puede depender de
        // que nadie la haya cambiado (regla dura 3).
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
