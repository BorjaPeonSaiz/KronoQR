<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * La licencia no se ha podido leer: la base de datos no responde o la tabla no
 * esta donde deberia (V21-4-PL-1, RF-PD-04, ADR-019).
 *
 * ## Por que no es «sin licencia»
 *
 * Hasta la 2.3.0 la lectura devolvia `null` ante cualquier fallo, y `null` es
 * `absent`. Con PostgreSQL caido, el paquete de diagnostico y `doctor` decian
 * «no hay ninguna licencia activada» y mandaban a `license:activate` a un hotel
 * con una licencia valida, en plena caida. No saber no es saber que no hay:
 * quien la recibe la traduce a `unknown`, que **no degrada nada, no avisa de
 * nada y no se guarda en ningun sitio** como estado conocido.
 *
 * El mensaje no lleva la causa: la clase de la excepcion de origen va en
 * `previous`, que nadie escribe en un log.
 */
final class LicenseUnavailable extends RuntimeException
{
    public static function because(Throwable $cause): self
    {
        return new self('The licence could not be read from storage.', 0, $cause);
    }
}
