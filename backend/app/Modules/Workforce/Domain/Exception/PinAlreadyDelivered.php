<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Se registra dos veces la entrega del mismo PIN (RF-ID-09).
 *
 * **No es idempotente a proposito**, por lo mismo que la revocacion de una
 * credencial: sobrescribir la primera entrega cambiaria el momento y el
 * responsable que ya constan en `audit_log`, y eso es precisamente lo que el
 * registro de entrega existe para poder afirmar. Si de verdad hubo una segunda
 * entrega, es que hubo un PIN nuevo: se restablece y se entrega ese.
 */
final class PinAlreadyDelivered extends WorkforceConflict
{
    public static function forEmployee(string $uuid): self
    {
        return new self('La entrega del PIN del empleado '.$uuid.' ya estaba registrada.');
    }
}
