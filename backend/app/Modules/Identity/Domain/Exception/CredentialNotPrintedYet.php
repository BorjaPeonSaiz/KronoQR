<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * Se ha pedido registrar la entrega de una credencial que todavia no se ha
 * impreso (RF-QR-06, ADR-034).
 *
 * Antes de la impresion no hay tarjeta: la fila existe, pero su QR no se ha
 * acuñado y nadie puede tener nada en la mano. Marcar la entrega ahi seria
 * escribir en el registro un acto que no ocurrio, y ese registro es justo el
 * que despues distingue «se perdio antes de darsela» de «la perdio el
 * empleado» (doc 02 §5.5).
 */
final class CredentialNotPrintedYet extends IdentityDomainException
{
    public static function forCredential(string $credentialUuid): self
    {
        return new self('La credencial '.$credentialUuid.' no se ha impreso todavia: no hay tarjeta que entregar.');
    }
}
