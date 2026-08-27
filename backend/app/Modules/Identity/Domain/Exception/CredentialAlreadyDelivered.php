<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * Se ha pedido registrar dos veces la entrega de la misma credencial
 * (RF-QR-06).
 *
 * La entrega es un acto presencial con fecha y responsable. Sobrescribirla
 * cambiaria quien la entrego y cuando, que es exactamente el dato que se
 * consulta meses despues cuando alguien discute un fichaje. Misma razon que
 * {@see CredentialAlreadyRevoked} y misma traduccion: 409.
 */
final class CredentialAlreadyDelivered extends IdentityDomainException
{
    public static function forCredential(string $credentialUuid): self
    {
        return new self('La entrega de la credencial '.$credentialUuid.' ya estaba registrada.');
    }
}
