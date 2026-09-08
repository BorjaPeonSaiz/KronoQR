<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * En que quedo un intento de revocar un acceso de soporte (RF-PD-11).
 *
 * **Tres resultados y no un booleano**, porque el contrato distingue dos de
 * ellos y el trail distingue los tres: `404` si el UUID no existe, `204` si se
 * revoco y `204` tambien si ya lo estaba —pero sin asiento, porque la segunda
 * pulsacion de un boton no es un hecho nuevo—.
 *
 * Con un booleano, el controlador tendria que volver a consultar la fila para
 * saber si el `false` era «no existe» o «ya estaba», que son dos consultas para
 * un dato que el caso de uso ya tenia.
 */
enum SupportRevocationOutcome
{
    /** No hay ninguna concesion con ese UUID. El endpoint responde `404`. */
    case NotFound;

    /** Estaba viva o caducada, y ahora consta revocada. Deja asiento. */
    case Revoked;

    /** Ya estaba revocada. `204` y sin asiento: no ha ocurrido nada nuevo. */
    case AlreadyRevoked;
}
