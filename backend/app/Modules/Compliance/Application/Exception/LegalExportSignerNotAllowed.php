<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;
use RuntimeException;

/**
 * Quien firma la exportacion legal pedida por consola ya no es una cuenta de
 * gestion activa con rol `admin`, `rrhh` o `auditor` (RF-IN-05, RL-04; doc 01
 * §7.2, «Firmas por consola»).
 *
 * Se comprueba antes de leer el registro y otra vez con la cadena de
 * `audit_log` tomada, justo antes del asiento: una baja entre la pregunta de la
 * consola y la firma no deja una exportacion a nombre de quien ya no responde.
 * No se escribe ningun asiento y el parcial no se publica: el caso de uso lo
 * borra. La consola sale con `2`. El mensaje no lleva la cuenta (regla dura
 * 21).
 */
final class LegalExportSignerNotAllowed extends RuntimeException implements BusinessRejection
{
    public function __construct()
    {
        parent::__construct('The account given for the legal export is no longer an active management account that can sign it.');
    }
}
