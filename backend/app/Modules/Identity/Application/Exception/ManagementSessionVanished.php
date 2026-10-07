<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * El token de la sesion desde la que se cambiaba la contrasena propia desaparecio
 * a mitad del cambio —una baja o un restablecimiento cruzados— (RF-ID-10).
 *
 * Es la señal con la que `ChangeOwnPasswordHandler` deshace la transaccion
 * entera; no sale del caso de uso, que la traduce a `401`.
 */
final class ManagementSessionVanished extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La sesion desde la que se cambiaba la contrasena ya no existe.');
    }
}
