<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/**
 * Persistencia del agregado `WorkDay`.
 *
 * Lo implementa la propia `Attendance/Infrastructure/Persistence` (tarea 1.4),
 * asi que es el unico de los cinco puertos del nucleo que puede hablar en tipos
 * del dominio de Attendance: los otros cuatro los sirve un satelite y por eso
 * solo pueden usar tipos de `Shared` o escalares (ADR-025, restriccion 2).
 *
 * Nombres del negocio y no del ORM: `findOpenWorkDayFor`, no
 * `getByEmployeeIdAndDate`.
 */
interface WorkDayRepository
{
    /**
     * La jornada del empleado que tiene un tramo abierto, si la hay.
     *
     * **Es la consulta que decide si un escaneo abre o cierra turno**
     * (RF-AT-02, RF-AT-03), y por eso pregunta por el turno abierto y no por la
     * fecha de hoy: un turno de noche que entro ayer a las 22:00 se cierra a
     * las 06:00 dentro de la jornada de **ayer** (RN-05, ADR-006). Buscar por la
     * fecha del escaneo abriria una jornada nueva a las 06:00 y partiria el
     * turno, que es lo que la regla dura 4 prohibe.
     *
     * Igual sirve para la vuelta de una pausa, que continua la jornada abierta
     * y hereda su `work_date` aunque empiece en otro dia natural (ADR-024).
     */
    public function findOpenWorkDayFor(string $employeeUuid): ?WorkDay;

    /**
     * La jornada del empleado en una fecha concreta, si existe.
     *
     * Carga solo los tramos **vigentes**: los `voided` y los `superseded` son
     * historico y no forman parte del agregado (ADR-026).
     */
    public function findWorkDayFor(string $employeeUuid, WorkDate $workDate): ?WorkDay;

    /**
     * La jornada a la que pertenece un tramo vigente, si ese tramo existe.
     *
     * **Es la consulta con la que empieza una correccion** (RF-PA-04): el panel
     * envia el identificador del tramo, no la fecha, y quien tiene que decidir
     * si se puede tocar es el agregado que lo contiene. Buscar por la fecha que
     * el cliente crea que le corresponde volveria a partir los turnos de noche
     * por la puerta de atras (RN-05, ADR-006).
     *
     * Devuelve `null` cuando el tramo no existe **o ya no es vigente** —anulado
     * o sustituido—, porque en ninguno de los dos casos hay una jornada que
     * pueda corregirlo (ADR-026). Quien llama distingue el `404` del `409`
     * consultando el historico, que es donde esta la diferencia.
     */
    public function findWorkDayOfShiftEntry(string $shiftEntryUuid): ?WorkDay;

    /**
     * Guarda la jornada y **recalcula** su proyeccion en la misma transaccion
     * (RN-06, ADR-007). Nunca incrementa el total acumulado.
     *
     * **Escribe tambien los tramos retirados** —`WorkDay::retiredEntries()`: los
     * anulados y los sustituidos— con su estado nuevo y su `superseded_by_id`, y
     * **antes** de insertar la version que los sustituye (regla dura 5,
     * ADR-026). El orden no es cosmetico: mientras la version anterior siga
     * siendo vigente para PostgreSQL, la nueva pisa su intervalo y
     * `shift_entries_no_overlap` aborta la correccion entera.
     *
     * El recalculo de `daily_totals` se hace sobre el conjunto vigente, que es
     * justo lo que `WorkDay::totalWorked()` devuelve: la version sustituida ya
     * no cuenta, y por eso corregir un tramo de 480 a 450 minutos deja el dia en
     * 450 y no en 930.
     */
    public function save(WorkDay $workDay): void;
}
