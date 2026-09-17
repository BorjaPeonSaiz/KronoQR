<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\ComplianceIncidentLink;

/**
 * La incidencia de la bandeja que describe el mismo hecho que un hallazgo
 * (RF-PA-06, paso 4 de la ficha).
 *
 * ## Por que un puerto y no una consulta mas dentro del lector de hechos
 *
 * Porque son dos afirmaciones distintas y conviene que se puedan romper por
 * separado. El lector dice **que ocurrio**; esto dice **si alguien ya lo tiene
 * anotado**. Un hallazgo sin incidencia no es un error: significa que la revision
 * diaria todavia no ha pasado por esa jornada, o que la regla no abre incidencias
 * (RN-17), o que esta suspendida (RN-12). Mezclarlo con los hechos haria que un
 * fallo del enlace pareciera un fallo del registro.
 *
 * ## La clave es `(empleado, jornada, tipo)`
 *
 * La misma con la que `incidents` declara su restriccion de unicidad, sin el
 * tramo: un hallazgo señala la jornada y la incidencia puede estar colgada de un
 * tramo concreto. Si hubiera mas de una para la misma terna —el caso de RN-08 y
 * RN-11, que comparten el tipo `long_shift`— se devuelve **la abierta**, que es
 * la que quien revisa tiene que trabajar.
 */
interface ComplianceIncidentLinks
{
    /**
     * Las incidencias que corresponden a las ternas pedidas.
     *
     * Se pregunta **por lote y no hallazgo a hallazgo**: una vista de cuatro
     * semanas puede traer cientos, y un `N+1` contra `incidents` en el camino de
     * una pantalla que RRHH abre cada mañana es exactamente lo que RNF-P-02
     * prohibe.
     *
     * @param  list<array{employee_uuid: string, work_date: string, type: string}>  $keys
     * @return array<string, ComplianceIncidentLink> indexado por `uuid|fecha|tipo`
     */
    public function linksFor(array $keys): array;
}
