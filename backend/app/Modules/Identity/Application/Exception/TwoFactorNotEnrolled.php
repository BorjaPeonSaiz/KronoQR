<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Se intenta confirmar un segundo factor que nunca se dio de alta (RS-06).
 *
 * Ocurre cuando alguien llama a `/auth/2fa/confirm` sin haber pasado por
 * `/auth/2fa/enrol`, o cuando el alta se hizo con otra sesion pendiente que ya
 * fue sustituida. `409`, como la anterior: el estado del recurso no es el que la
 * peticion supone.
 */
final class TwoFactorNotEnrolled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No hay ningun segundo factor pendiente de confirmar en esta cuenta.');
    }
}
