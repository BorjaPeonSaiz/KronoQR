<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Una persona no puede tener dos contratos vigentes el mismo dia
 * (**RF-GP-02**, RF-IN-03).
 *
 * ## Por que es una invariante y no una preferencia
 *
 * Lo contratado del periodo se prorratea dia a dia contra el contrato **vigente
 * ese dia** (RF-IN-03). Con dos vigencias solapadas, la pregunta «¿cuantas
 * horas tenia contratadas el 14 de marzo?» tiene dos respuestas, y el informe de
 * trabajadas frente a contratadas —que acaba delante de alguien discutiendo una
 * nomina— tendria que elegir una por su cuenta. Por eso el solape no se resuelve
 * eligiendo: no se admite.
 *
 * ## Se lanza al chocar con la restriccion, no tras un `SELECT`
 *
 * Mismo criterio que {@see WorkforceConflict}: comprobar antes con una consulta
 * es una condicion de carrera con aspecto de comprobacion —dos altas simultaneas
 * la pasan las dos—. Quien la hace cumplir de verdad es
 * `employment_contracts_no_overlap`, la restriccion de exclusion `EXCLUDE USING
 * gist` de la migracion.
 *
 * `409` y no `422`: el cuerpo es correcto, lo que pasa es que ya hay un contrato
 * ahi. La accion siguiente es releer los contratos de esa persona, no reescribir
 * el formulario.
 */
final class OverlappingEmploymentContract extends WorkforceConflict
{
    public static function forEmployee(string $employeeUuid, string $validFrom): self
    {
        return new self(
            // Sin el nombre de nadie (regla dura 21): el UUID publico es el
            // identificador con el que trabaja la API y el panel.
            'Ya hay un contrato vigente para el empleado '.$employeeUuid.' en la fecha '.$validFrom.'.',
        );
    }
}
