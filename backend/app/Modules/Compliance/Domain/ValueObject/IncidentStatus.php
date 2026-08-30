<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * En que punto esta una incidencia (`incidents.status`, doc 01 §5.5).
 *
 * **Tres estados, y dos de ellos son finales.** La diferencia entre `resolved`
 * y `dismissed` no es cosmetica: la primera dice «habia algo y se ha arreglado»
 * —normalmente con una correccion trazada de RF-PA-04— y la segunda dice «se ha
 * mirado y no habia nada que arreglar». Con un solo estado final, el indicador
 * «tiempo medio hasta resolver» del doc 01 §9.2 mezclaria trabajo real con
 * falsos positivos y no serviria para ajustar los umbrales.
 *
 * **No hay `acknowledged`.** Un estado intermedio de «visto» solo tiene sentido
 * con reparto de trabajo entre varias personas, y aqui la incidencia ya nace
 * asignada al responsable del departamento.
 *
 * Solo `open` cuenta para la metrica `incidents_open{type,severity}` (doc 02
 * §8.2). La restriccion `one_incident_per_finding` que hace idempotente la
 * deteccion, en cambio, **no mira el estado**: una incidencia cerrada sigue
 * impidiendo que el mismo hallazgo —mismo empleado, misma jornada, mismo tipo y
 * mismo tramo— vuelva a abrirse. Cerrar no es olvidar, y un tramo cerrado no
 * produce hechos nuevos; los que si lo son llegan con otro `shift_entry_id`,
 * porque una correccion estrena identificador (ADR-035).
 */
enum IncidentStatus: string
{
    /** Pendiente de que alguien la trabaje. */
    case Open = 'open';

    /** Habia algo y se ha arreglado. */
    case Resolved = 'resolved';

    /** Se ha mirado y no habia nada que arreglar. */
    case Dismissed = 'dismissed';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
