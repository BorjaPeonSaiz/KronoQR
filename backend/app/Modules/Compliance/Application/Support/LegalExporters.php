<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Support;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede generar la exportacion legal del registro horario (RF-IN-05,
 * RF-ID-03, Anexo B del doc 01): `admin`, `rrhh` y `auditor`.
 *
 * Una sola lista para los tres caminos —`LegalExportPolicy` en el panel,
 * `compliance:legal-export` en la consola y la comprobacion de
 * `GenerateLegalExport` con la cadena tomada—, de modo que la consola nunca
 * permita lo que la API niega (doc 01 §7.2). Por que esta cada rol y por que
 * no estan los demas lo cuenta `LegalExportPolicy`.
 */
final class LegalExporters
{
    /** @var list<UserRole> */
    public const array ROLES = [UserRole::ADMIN, UserRole::RRHH, UserRole::AUDITOR];
}
