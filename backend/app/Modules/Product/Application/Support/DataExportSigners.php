<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Support;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede llevarse la exportacion integra (RF-PD-14, RL-20): solo `admin`.
 *
 * Una sola lista para los tres caminos —`DataExportPolicy` en el panel,
 * `product:export-all` en la consola y la comprobacion de
 * `RequestDataExportHandler` con la cadena tomada—, de modo que la consola
 * nunca permita lo que la API niega (doc 01 §7.2). El panel excluye ademas al
 * actor de soporte; en la consola no hay sesion de soporte que excluir.
 */
final class DataExportSigners
{
    /** @var list<UserRole> */
    public const array ROLES = [UserRole::ADMIN];
}
