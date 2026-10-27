<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use RuntimeException;

/**
 * Quien responde de la entrega ya no es una cuenta de gestion activa con rol
 * admin o rrhh cuando se va a firmar (RF-QR-06, RL-04).
 *
 * Se comprueba con la cadena de `audit_log` tomada, justo antes de marcar la
 * entrega: la baja de una cuenta toma la cadena antes de tocar su fila
 * (ADR-051 §6), asi que una baja entre la pregunta de la consola —o la
 * autorizacion del panel— y la firma no deja una entrega a nombre de alguien
 * que ya no responde. No se entrega ni se escribe nada. La consola sale con
 * `2`; el panel responde `403`, como cualquier otra denegacion.
 *
 * El mensaje no lleva la cuenta (regla dura 21).
 */
final class CredentialDeliverySignerNotAllowed extends RuntimeException implements BusinessRejection
{
    public function __construct()
    {
        parent::__construct('The account given for the delivery is no longer an active management account that can sign it.');
    }
}
