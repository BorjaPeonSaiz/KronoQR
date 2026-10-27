<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * La licencia no se ha podido leer porque **la base de datos no responde**: el
 * circuito de PostgreSQL esta abierto o la conexion se ha perdido (RF-PD-04, ADR-019, ADR-059 §4).
 *
 * ## Solo esa causa
 *
 * Con PostgreSQL respondiendo, un fallo al leer `license` —un permiso retirado,
 * una tabla renombrada— **no** es esto: el adaptador devuelve «sin licencia» y
 * se deniega lo accesorio. `unknown` lo concede todo, y si cualquier fallo lo
 * produjera, una sola sentencia SQL saltaria la licencia.
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
        return new self('The license could not be read from storage.', 0, $cause);
    }
}
