<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * Se ha intentado emitir o reemitir una credencial a una persona **de baja**
 * (RN-14).
 *
 * La baja revoca todas sus tarjetas en el mismo acto (N1,
 * `RevokeCredentialsOfOffboardedEmployee`). Emitirle otra despues dejaria una
 * credencial pendiente de imprimir a nombre de alguien que ya no trabaja aqui:
 * no podria fichar con ella —RN-14 la rechaza en el escaneo—, pero contaria en
 * el panel de RF-QR-08 como una tarjeta por entregar y se podria imprimir.
 *
 * Solo la baja (`terminated`). Una persona **suspendida** conserva su ficha y
 * vuelve; la especificacion no dice que no pueda tener su tarjeta preparada, y
 * esta regla no lo decide por su cuenta.
 *
 * Se traduce a un `409` en la API, como `EmployeeAlreadyHasCredential`: no hay
 * ningun campo que corregir, la persona esta en un estado que no lo admite.
 */
final class CredentialHolderIsOffboarded extends IdentityDomainException
{
    public static function make(): self
    {
        return new self('La persona esta de baja: no se le emite ni se le reemite ninguna credencial (RN-14).');
    }
}
