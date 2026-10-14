<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * No se pudo guardar el asiento pendiente de un paquete de diagnostico generado
 * con la base de datos caida (ADR-020, enmienda del 14-10-2026).
 *
 * Sin pendiente, el paquete **no se entrega**: su asiento se perderia. El
 * mensaje lleva el directorio y que hacer, que es lo que necesita quien esta
 * delante de la terminal; nunca el mensaje de la excepcion de origen.
 */
final class DiagnosticsAuditNotDeferred extends ProductDomainException
{
    public static function in(string $directory): self
    {
        return new self(
            'No se pudo escribir el asiento pendiente del paquete en '.$directory
            .'. Comprueba que storage esta montado y que el usuario de la aplicacion puede escribir en el'
            .' directorio (php artisan product:doctor).'
        );
    }
}
