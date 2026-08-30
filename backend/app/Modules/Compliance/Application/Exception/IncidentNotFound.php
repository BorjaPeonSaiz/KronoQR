<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use RuntimeException;

/**
 * No existe la incidencia que se pide (RF-PA-05).
 *
 * **`404` y no `403`, y el orden importa**: quien se equivoca de identificador
 * tiene que recibir «eso no existe» y no un asiento de intento fuera de alcance a
 * nombre de nadie. Por eso el caso de uso comprueba primero que la fila existe y
 * solo despues si quien pregunta la alcanza; al reves, `audit_log` se llenaria de
 * erratas.
 *
 * No lleva el identificador en el mensaje de cara al cliente: la respuesta es la
 * generica de `NotFound` del contrato. El numero queda en el log del servidor,
 * que es donde sirve para algo.
 */
final class IncidentNotFound extends RuntimeException
{
    public static function withId(int $incidentId): self
    {
        return new self('No existe la incidencia '.$incidentId.'.');
    }
}
