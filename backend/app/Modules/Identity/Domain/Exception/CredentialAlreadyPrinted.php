<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * Se ha pedido imprimir una credencial cuyo token ya se acuño (ADR-034,
 * RF-QR-04).
 *
 * **Reimprimir no existe.** El token nace en la impresion y no se puede volver
 * a leer, asi que «imprimir otra vez» solo puede significar «acuñar otro
 * token», y eso deja muerta la tarjeta que quiza ya esta en el bolsillo de
 * alguien. Si de verdad hace falta otra tarjeta —perdida, rotura, rotacion de
 * clave— el camino es revocar, reemitir e imprimir la nueva: tres actos
 * distintos, los tres en `audit_log`, que es lo que el runbook
 * `tarjeta-perdida-o-rota.md` describe.
 *
 * Es tambien lo que hace que ejecutar dos veces `credentials:print-batch` sea
 * inofensivo: la segunda pasada no encuentra nada pendiente, y si alguien
 * apunta a una credencial concreta, esta excepcion la para.
 *
 * La API lo traduce a un 409.
 */
final class CredentialAlreadyPrinted extends IdentityDomainException
{
    public static function forCredential(string $credentialUuid): self
    {
        return new self('La credencial '.$credentialUuid.' ya se imprimio: su token no se puede volver a acuñar.');
    }
}
