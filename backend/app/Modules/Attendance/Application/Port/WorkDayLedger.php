<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;

/**
 * Lectura **en bloque** del registro horario, para la revision diaria
 * (RF-PR-01, tarea 2.6).
 *
 * **Por que no es un metodo mas de {@see WorkDayRepository}.** Aquel sirve al
 * fichaje: carga una jornada, la guarda y traduce las violaciones de RN-01 y
 * RN-02. Este solo lee, no guarda nunca y recorre a toda la plantilla. Juntarlos
 * daria un puerto que un caso de uso implementa a medias y que ademas invita a
 * escribir desde la deteccion, que es exactamente lo que RN-08 prohibe.
 *
 * Lo implementa la propia `Attendance/Infrastructure/Persistence`, asi que puede
 * hablar en tipos del dominio de Attendance (ADR-025, restriccion 2).
 */
interface WorkDayLedger
{
    /**
     * Las jornadas con **algun tramo todavia abierto**, sea cual sea su fecha.
     *
     * No lleva ventana a proposito, y es la excepcion declarada a la decision de
     * retroactividad (doc 01 §4): un turno sin cerrar no es historia, es un
     * hecho presente que sigue creciendo. Un olvido de salida de hace tres meses
     * tiene que seguir apareciendo hasta que alguien lo corrija — es justo lo que
     * la alerta «Turnos abiertos > 12 h» existe para ver.
     *
     * Son unas pocas filas aunque la tabla tenga millones: las sirve el indice
     * unico parcial `one_open_shift_per_employee`.
     *
     * @return list<WorkDay>
     */
    public function openWorkDays(): array;

    /**
     * Las jornadas con tramos vigentes entre esas dos fechas, ambas incluidas.
     *
     * Ordenadas por empleado y fecha para que quien las recorra pueda razonar
     * sobre la secuencia de una persona sin reordenar nada.
     *
     * @return list<WorkDay>
     */
    public function workDaysBetween(WorkDate $from, WorkDate $to): array;

    /**
     * Fin del ultimo tramo **cerrado** anterior a ese instante, o `null` si no
     * consta ninguno.
     *
     * Es la mitad que le falta a RN-10: el descanso se mide entre el fin de la
     * jornada anterior y el inicio de esta, y la anterior puede caer fuera de la
     * ventana revisada. Devolver `null` cuando no hay nada es significativo —el
     * dominio entonces **no evalua** la regla— y no un cero disfrazado.
     */
    public function lastClockOutBefore(string $employeeUuid, DateTimeImmutable $instant): ?DateTimeImmutable;
}
