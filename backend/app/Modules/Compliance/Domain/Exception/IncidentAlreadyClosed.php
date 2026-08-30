<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * Se ha intentado cerrar una incidencia que ya estaba cerrada (RF-PA-05).
 *
 * **Es un `409` y no un `422`**: quien la recibe no tiene ningun campo que
 * corregir —el cuerpo es correcto—, lo que pasa es que el estado del recurso ya
 * no es el que la peticion suponia. La accion siguiente es releer la bandeja, no
 * reescribir la nota.
 *
 * Cubre los dos caminos por los que se llega al mismo sitio, y a proposito: la
 * segunda pestaña que resuelve lo que la primera ya resolvio, y la carrera de
 * dos peticiones simultaneas que el `UPDATE ... WHERE status = 'open'` del
 * repositorio decide. Para quien llama el desenlace es identico —esa incidencia
 * ya esta trabajada— y distinguirlos solo le diria si llego tarde por segundos o
 * por horas.
 */
final class IncidentAlreadyClosed extends ComplianceDomainException
{
    public static function inStatus(string $status): self
    {
        return new self(
            'Esa incidencia ya esta cerrada («'.$status.'») y no se resuelve dos veces. '
            .'Vuelve a cargar la bandeja para ver quien la trabajo y con que nota.'
        );
    }
}
