<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Una persona no puede tener dos ausencias activas el mismo dia
 * (**RF-GP-04**).
 *
 * ## Por que es una invariante y no una preferencia
 *
 * El informe por periodo cuenta **dias-persona** cubiertos por una ausencia
 * activa. Con dos ausencias solapadas, el mismo dia se contaria dos veces y el
 * numero de absentismo justificado de un departamento dejaria de cuadrar con el
 * numero de personas que hay en el. Ese numero acaba delante de alguien
 * discutiendo un cuadrante o una nomina.
 *
 * ## Se lanza al chocar con la restriccion, no tras un `SELECT`
 *
 * Mismo criterio que {@see OverlappingEmploymentContract}: quien la hace
 * cumplir de verdad es `absences_no_overlap`, la restriccion de exclusion
 * `EXCLUDE USING gist ... WHERE (status = 'active')` de la migracion. Una
 * comprobacion previa desde PHP es una condicion de carrera con aspecto de
 * comprobacion: dos altas simultaneas la pasan las dos.
 *
 * `409` y no `422`: el cuerpo es correcto, lo que pasa es que ya hay algo ahi.
 * La accion siguiente es releer el historial de esa persona, no reescribir el
 * formulario.
 *
 * **Sin el tipo de la ausencia en el mensaje** (regla dura 21): el mensaje de
 * una excepcion acaba en un log tecnico y en `error_events`, y «baja medica» ahi
 * es un dato de salud. Van el UUID publico y las fechas, que es lo que hace
 * falta para encontrarla.
 */
final class OverlappingAbsence extends WorkforceConflict
{
    public static function forEmployee(string $employeeUuid, string $startsOn, string $endsOn): self
    {
        return new self(
            'Ya hay una ausencia registrada para el empleado '.$employeeUuid
            .' en alguno de los dias entre '.$startsOn.' y '.$endsOn.'.',
        );
    }
}
