<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Los filtros del listado de cuentas de gestion (RF-ID-10,
 * `GET /api/v1/management-accounts`, `identity:list-users`).
 *
 * Los tres se combinan con `AND` y actuan sobre el conjunto entero, no sobre la
 * pagina devuelta. `null` es «sin filtro».
 */
final readonly class ManagementAccountFilter
{
    /**
     * @param  string|null  $search  Subcadena sobre el nombre o el correo, sin
     *                               distinguir mayusculas ni acentos. Ya recortada:
     *                               una cadena vacia no llega aqui.
     * @param  bool|null  $active  `true` solo activas, `false` solo dadas de baja.
     * @param  UserRole|null  $role  Solo las cuentas que tienen ese rol.
     */
    public function __construct(
        public ?string $search = null,
        public ?bool $active = null,
        public ?UserRole $role = null,
    ) {}
}
