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

    /**
     * La fila de esa jornada **con candado de escritura**, o `null` si no existe.
     *
     * **Sigue sin escribir: bloquear no es escribir.** Es la lectura que la
     * correccion hace dentro de su propia transaccion, justo antes de decidir si
     * publica el recalculo, y el candado es lo unico que la separa de pisar el
     * trabajo de un fichaje que esta confirmando en ese mismo instante. Sin el,
     * la reconciliacion compara una lectura vieja —tomada en la pasada de
     * inspeccion, milisegundos antes— contra una fila que ya cambio, y
     * «corrige» con datos caducados una fila que estaba bien (RN-06, regla dura
     * 7).
     *
     * El candado sirve porque el fichaje escribe el tramo y esta fila en la
     * **misma** transaccion (ADR-007): quien tenga la fila tomada obliga al
     * `UPSERT` del fichaje a esperar, y quien llegue despues reescribe con sus
     * propios valores, que son los buenos. Lo unico que el candado no cubre es
     * la fila que **todavia no existe**, porque no hay nada que bloquear; ese
     * residuo esta escrito en el caso de uso.
     *
     * Devolver `null` es significativo —no hay fila, no hay candado— y no
     * equivale a una fila a cero: la diferencia entre las dos cosas es
     * exactamente la divergencia «fila ausente».
     */
    public function lockedFor(string $employeeUuid, WorkDate $workDate): ?ProjectedDailyTotal;
}
