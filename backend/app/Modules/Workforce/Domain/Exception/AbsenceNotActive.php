<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Se ha intentado corregir o anular una version que ya no es la vigente
 * (**RF-GP-04**, RN-13, ADR-035).
 *
 * El `uuid` de una ausencia identifica una **version**, no una ausencia a lo
 * largo del tiempo, con el mismo criterio que `shift_entries`. Dos personas
 * mirando la misma pantalla pueden corregir la misma fila a la vez: la segunda
 * llega con el identificador de una version que ya paso a `superseded`, y
 * dejarla escribir crearia dos ramas del historial sin forma de decidir cual es
 * la buena.
 *
 * `409` y no `422`: el cuerpo es valido y lo que no encaja es el estado. La
 * accion siguiente es releer el historial de esa ausencia.
 *
 * **Sin el tipo ni la nota en el mensaje** (regla dura 21): solo el `uuid`
 * publico y el estado en el que esta, que es un valor de un catalogo de tres.
 */
final class AbsenceNotActive extends WorkforceConflict
{
    public static function forAbsence(string $absenceUuid, string $status): self
    {
        return new self('La ausencia '.$absenceUuid.' ya no es la version vigente (esta en «'.$status.'»).');
    }
}
