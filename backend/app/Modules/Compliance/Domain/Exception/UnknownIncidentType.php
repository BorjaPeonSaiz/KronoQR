<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Un hallazgo llega con un tipo que `incidents.type` no conoce (RF-PR-01).
 *
 * Falla cerrado a proposito. Descartarlo en silencio dejaria una situacion ya
 * detectada —un turno abierto de trece horas, un descanso por debajo del
 * minimo— sin nadie que la revise, que es justo lo contrario de para lo que
 * existe la deteccion. Lo que hay que hacer al verlo es declarar el tipo en las
 * tres copias del catalogo: este enum, `AnomalyType` de `Attendance` y el CHECK
 * de la migracion.
 */
final class UnknownIncidentType extends ComplianceDomainException
{
    public function __construct(string $type)
    {
        parent::__construct(sprintf(
            'El tipo de incidencia «%s» no esta en el catalogo de `incidents.type`. '
            .'Si lo ha añadido la deteccion de Attendance, declaralo tambien en IncidentType y en la migracion.',
            $type,
        ));
    }
}
