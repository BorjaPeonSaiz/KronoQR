<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Support;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede sacar un paquete de diagnostico con datos personales (RL-19,
 * ADR-020): solo `admin`.
 *
 * Una sola lista para los tres caminos —`DiagnosticsPolicy` en el panel,
 * `product:diagnostics --with-personal-data` en la consola y la comprobacion
 * de `GenerateDiagnosticsBundleHandler` con la cadena tomada—, de modo que la
 * consola nunca permita lo que la API niega (2.3.0 bloque 3, doc 01 §7.2).
 */
final class DiagnosticsSigners
{
    /** @var list<UserRole> */
    public const array ROLES = [UserRole::ADMIN];
}
