<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use App\Modules\Reporting\Domain\Model\ReportExport;
use DomainException;

/**
 * Quien pide ya tiene un informe en diferido `pending` o `running`
 * (**RF-IN-06**, decision 2 de la ficha 3.9).
 *
 * ## Una en curso **por persona**, no por instalacion
 *
 * Al contrario que la exportacion integra de RF-PD-14, donde el limite es de la
 * instalacion entera porque recorre todas las tablas. Aqui el trabajo es una
 * consulta acotada por alcance y por periodo: que RRHH este generando el cierre
 * de mes no puede impedirle a un responsable pedir el suyo. Lo que si se impide
 * es que una misma persona llene la cola pulsando el boton diez veces, que es el
 * caso real —el panel tarda en refrescar y se vuelve a pulsar—.
 *
 * ## Lleva dentro la que ocupa el turno
 *
 * Para que el `409` la incluya en el cuerpo y el panel enseñe **esa** en lugar de
 * invitar a pedir otra. Puede venir nula: si la que estorbaba termino entre el
 * choque contra el indice unico y la relectura, el `409` sigue siendo correcto
 * —esta peticion no llego a crearse— y lo unico util que se puede decir es que
 * se reintente.
 */
final class ReportExportAlreadyInProgress extends DomainException
{
    public function __construct(public readonly ?ReportExport $current = null)
    {
        parent::__construct('Ya hay un informe en diferido en curso para este solicitante.');
    }
}
