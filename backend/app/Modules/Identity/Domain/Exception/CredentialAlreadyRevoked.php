<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * Se ha pedido revocar una credencial que ya estaba revocada (RF-QR-03).
 *
 * **No se trata como exito silencioso.** Revocar dos veces suele significar que
 * quien lo pide esta mirando una pantalla desactualizada, o que hay dos personas
 * atendiendo la misma perdida de tarjeta. Sobrescribir la primera revocacion
 * cambiaria el motivo y el momento que ya constan en `audit_log`, y eso es
 * reescribir un registro con relevancia legal (regla dura 5).
 *
 * La API lo traduce a un 409, que es lo que el contrato describe.
 */
final class CredentialAlreadyRevoked extends IdentityDomainException
{
    public static function forCredential(string $credentialUuid): self
    {
        return new self('La credencial '.$credentialUuid.' ya estaba revocada.');
    }
}
