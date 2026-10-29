<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use DateTimeImmutable;

/**
 * **¿Puede seguir creciendo todavia la jornada del cese?** (RN-14, RN-05,
 * ADR-062 §4).
 *
 * La pregunta la hace la tarea que aplica las bajas programadas (`Workforce`) y
 * la contesta el registro horario (`Attendance`), porque la cota de continuacion
 * de una pausa es suya. Vive en `Shared` porque ninguno de los dos modulos puede
 * importar al otro (ADR-025, doc 02 §1.6): es la misma salida que
 * {@see SerializedLedgerWrite} o {@see EmploymentStatusLookup}.
 *
 * `true` si la persona tiene un tramo abierto de una jornada hasta el cese, o
 * una pausa de esa jornada cuya vuelta aun cabe bajo la cota de continuacion en
 * `$at`. Mientras sea `true`, la tarea aplaza la baja para que el turno de noche
 * y la pausa de madrugada del ultimo dia terminen fichados y no con una
 * correccion. El tope del aplazamiento no es de este puerto: lo pone la tarea.
 *
 * **Solo lectura**, y se llama con la cadena de auditoria tomada: un fichaje que
 * aun no ha confirmado no se ve, y su tramo abierto tampoco (ADR-062 §5).
 */
interface FinalWorkDayActivity
{
    /**
     * @param  string  $terminatedOn  fecha civil de cese, `Y-m-d`
     * @param  DateTimeImmutable  $at  el instante de la pasada, en UTC
     */
    public function mayStillGrow(string $employeeUuid, int $siteId, string $terminatedOn, DateTimeImmutable $at): bool;
}
