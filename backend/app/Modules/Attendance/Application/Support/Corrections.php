<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Support;

use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Shared\Domain\Event\DomainEvent;
use RuntimeException;

/**
 * Saca del lote que devuelve `WorkDay::releaseEvents()` el evento de la
 * correccion.
 *
 * Los tres casos de uso de la tarea 1.15 necesitan lo mismo: publicar todos los
 * eventos y, ademas, pasarle **ese** al libro de correcciones. Escribir el
 * `instanceof` en cada uno acabaria con tres versiones ligeramente distintas del
 * mismo bucle.
 *
 * Que falte es un error de programa, no un caso de negocio: significa que un
 * metodo del agregado cambio el registro sin registrar la correccion que lo
 * explica, y eso deja `shift_corrections` sin la fila que RN-13 exige.
 */
final readonly class Corrections
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public static function in(array $events): ShiftCorrected
    {
        foreach ($events as $event) {
            if ($event instanceof ShiftCorrected) {
                return $event;
            }
        }

        throw new RuntimeException('The work day did not record any correction (RN-13).');
    }
}
