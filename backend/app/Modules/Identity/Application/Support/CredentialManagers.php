<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Los roles que gestionan las tarjetas: `rrhh+` del Anexo B, que resuelve a
 * `{admin, rrhh}` (RF-QR-04, RF-QR-06).
 *
 * Una sola lista para los tres caminos —`CredentialPolicy` en el panel,
 * `credentials:deliver` en la consola y la comprobacion de `DeliverCredential`
 * con la cadena tomada—, de modo que la consola nunca permita lo que la API
 * niega (doc 01 §7.2).
 */
final class CredentialManagers
{
    /** @var list<UserRole> */
    public const array ROLES = [UserRole::ADMIN, UserRole::RRHH];
}
