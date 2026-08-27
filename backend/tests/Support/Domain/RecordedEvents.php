<?php

declare(strict_types=1);

namespace Tests\Support\Domain;

use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Shared\Domain\Event\DomainEvent;
use RuntimeException;

/**
 * Saca del lote que devuelve `WorkDay::releaseEvents()` el evento del que habla
 * la prueba, ya con su tipo.
 *
 * Existe para que ninguna prueba tenga que escribir `$events[1]` ni un
 * `instanceof` para estrechar el tipo: el indice acopla la prueba al orden en
 * que el agregado registra los eventos, que no es lo que esta comprobando, y el
 * `instanceof` mete una rama en un sitio donde no debe haberlas.
 */
final class RecordedEvents
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public static function clockedIn(array $events): EmployeeClockedIn
    {
        return self::last(EmployeeClockedIn::class, $events);
    }

    /**
     * @param  list<DomainEvent>  $events
     */
    public static function clockedOut(array $events): EmployeeClockedOut
    {
        return self::last(EmployeeClockedOut::class, $events);
    }

    /**
     * @param  list<DomainEvent>  $events
     */
    public static function dailyTotals(array $events): DailyTotalsRecalculated
    {
        return self::last(DailyTotalsRecalculated::class, $events);
    }

    /**
     * @param  list<DomainEvent>  $events
     * @return list<string>
     */
    public static function names(array $events): array
    {
        return array_map(static fn (DomainEvent $event): string => $event->eventName(), $events);
    }

    /**
     * El ULTIMO evento de ese tipo del lote, que es el que describe como queda
     * la jornada.
     *
     * Importa en el lote de una entrada seguida de una salida: hay dos
     * recalculos, y el que la proyeccion escribe —y del que habla la prueba— es
     * el segundo. Devolver el primero contaria el total de antes de cerrar.
     *
     * @template T of DomainEvent
     *
     * @param  class-string<T>  $class
     * @param  list<DomainEvent>  $events
     * @return T
     */
    private static function last(string $class, array $events): DomainEvent
    {
        $found = null;

        foreach ($events as $event) {
            $found = $event instanceof $class ? $event : $found;
        }

        return $found ?? throw new RuntimeException('El lote de eventos no contiene ningun '.$class.'.');
    }
}
