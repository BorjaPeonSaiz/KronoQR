<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use RuntimeException;

/**
 * Quien firma la revocacion pedida por consola ya no es una cuenta de gestion
 * activa con rol `admin` o `rrhh` cuando se va a firmar (RF-QR-03, RL-04; doc
 * 01 §7.2, «Firmas por consola»).
 *
 * Se comprueba con la cadena de `audit_log` tomada, antes de leer la tarjeta:
 * la baja de una cuenta toma la cadena antes de tocar su fila (ADR-051 §6),
 * asi que una baja entre la pregunta de la consola y la firma no deja una
 * revocacion a nombre de quien ya no responde. No se revoca ni se escribe
 * nada; la consola sale con `2`.
 *
 * El mensaje no lleva la cuenta (regla dura 21).
 */
final class CredentialRevocationSignerNotAllowed extends RuntimeException implements BusinessRejection
{
    public function __construct()
    {
        parent::__construct('The account given for the revocation is no longer an active management account that can sign it.');
    }
}
