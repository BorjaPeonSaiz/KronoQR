<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Se intenta registrar la entrega de un PIN que no existe (RF-ID-09).
 *
 * Es un `409` y no un `422`: la peticion es valida: lo que no encaja es el
 * estado. La salida no es corregir el formulario, es restablecer el PIN y
 * entregar ese.
 */
final class PinNotIssued extends WorkforceConflict
{
    public static function forEmployee(string $uuid): self
    {
        return new self('El empleado '.$uuid.' no tiene ningun PIN emitido que entregar.');
    }
}
