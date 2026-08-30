<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/**
 * Lectura de la proyeccion `daily_totals` para contrastarla con sus eventos
 * origen (RF-PR-02, ADR-007, tarea 2.7).
 *
 * **Solo lee, y es deliberado.** La proyeccion tiene un unico camino de
 * escritura —el listener `DailyTotalsProjector`, que reescribe la fila entera
 * con el estado que transporta `DailyTotalsRecalculated`— y la reconciliacion no
 * abre un segundo. Se nombra en prosa y no con un {@see}: un puerto de
 * `Application` no puede citar una clase de `Infrastructure` ni en un docblock,
 * porque Deptrac analiza tambien las referencias de tipo. Si este
 * puerto tuviera un `save()`, la reconciliacion podria «arreglar» una fila con
 * una aritmetica propia, y el dia que las dos formulas divergieran nadie se
 * enteraria: la comprobacion estaria hecha por el mismo codigo que se comprueba.
 *
 * **Por que es un puerto aparte de {@see WorkDayLedger}.** Aquel lee el registro
 * horario —la fuente de verdad—; este lee la copia derivada. Juntarlos daria un
 * puerto que mezcla las dos caras de la comparacion y haria facil escribir por
 * descuido una reconciliacion que compara la proyeccion consigo misma.
 *
 * Lo implementa la propia `Attendance/Infrastructure`, asi que puede hablar en
 * tipos del dominio de Attendance (ADR-025, restriccion 2).
 */
interface DailyTotalsProjection
{
    /**
     * Las filas escritas entre esas dos fechas, ambas incluidas.
     *
     * **Incluye las que no tienen ningun tramo vigente detras** —una jornada
     * anulada por completo—, que es justo el caso que una consulta guiada por
     * `shift_entries` no encontraria nunca y que deja un total pagado sobre un
     * dia que ya no existe.
     *
     * @return list<ProjectedDailyTotal>
     */
    public function between(WorkDate $from, WorkDate $to): array;
}
