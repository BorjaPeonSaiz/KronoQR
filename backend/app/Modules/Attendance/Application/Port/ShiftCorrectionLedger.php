<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Event\ShiftCorrected;

/**
 * El libro de correcciones del registro horario: `shift_corrections` (doc 01
 * §5.5, RN-13, RL-04).
 *
 * **Por que existe separado del repositorio.** `WorkDayRepository` guarda el
 * estado del registro —que tramos hay y cuales son vigentes—; esto guarda **por
 * que ese estado cambio**, que es un hecho distinto y con otra vida: la fila de
 * `shift_corrections` sigue siendo verdad aunque el tramo al que se refiere sea
 * sustituido tres veces mas.
 *
 * **Por que no es un listener, como la auditoria.** El asiento de `audit_log` lo
 * escribe `Compliance` al recibir el evento, porque la auditoria es de
 * `Compliance` y `Attendance` no puede importarlo (doc 02 §1.6). Pero
 * `shift_corrections` es una tabla **de este modulo**: es parte del registro
 * horario, se consulta al pintar el detalle de una jornada (RF-PA-03) y se
 * exporta con el registro legal (RL-03). Sacarla a otro modulo obligaria a
 * `Attendance` a preguntarle a un tercero por su propio historico.
 *
 * **Se escribe dentro de la transaccion del caso de uso.** Una correccion sin
 * fila aqui es una jornada cuyas horas cambiaron sin que conste quien ni por
 * que: exactamente lo que RN-13 prohibe. Si esta escritura falla, la correccion
 * no se confirma.
 *
 * **Recibe el evento de dominio y no un DTO propio** a proposito: el evento ya
 * transporta las cuatro cosas que la fila necesita —accion, antes, despues y la
 * firma de RN-13— y son las mismas que recibe el asiento de auditoria. Dos
 * formas de decir lo mismo acabarian divergiendo en la tercera correccion que
 * alguien anada.
 */
interface ShiftCorrectionLedger
{
    /**
     * Escribe la fila de `shift_corrections` que explica esta correccion.
     *
     * Correspondencia con las columnas del doc 01 §5.5, para que quien la
     * implemente no tenga que deducirla:
     *
     * | Columna | De donde sale |
     * |---|---|
     * | `shift_entry_id` | `$correction->correctedShiftEntryUuid()`, resuelto a la clave interna |
     * | `performed_by_user_id` | `$correction->correction->performedByUserId` |
     * | `action` | `$correction->action->value` |
     * | `before` | `$correction->before`, o `null` en un alta manual |
     * | `after` | `$correction->after`, o `null` en una anulacion |
     * | `reason_code` | `$correction->correction->reason->code->value` |
     * | `reason_text` | `$correction->correction->reason->text` |
     * | `created_at` | `$correction->correction->performedAt` |
     *
     * `before` y `after` son JSONB con las dos marcas y la version a la que
     * pertenecen. **Nunca el nombre del empleado** (regla dura 21): el JSON
     * describe horas, no personas.
     */
    public function record(ShiftCorrected $correction): void;
}
