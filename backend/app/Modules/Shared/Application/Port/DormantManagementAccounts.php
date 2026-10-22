<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Cuantas cuentas de gestion activas llevan sin entrar mas de N dias, por rol
 * (ADR-060, tarea 11.3 B). Solo numeros: ni quienes ni sus correos.
 *
 * **En Shared y no en Identity** porque la leen dos modulos con una sola
 * consulta: Identity, para la metrica `management_accounts_dormant`, y Product,
 * para la comprobacion `access.dormant_accounts` de `product:doctor`. Dos
 * consultas escritas dos veces acabarian contando cosas distintas; Product no
 * puede depender de Identity (Deptrac), asi que el puerto vive aqui y lo
 * implementa Identity.
 */
interface DormantManagementAccounts
{
    /**
     * @return array<string, int> Rol => cuentas, sin los roles que no tienen ninguna.
     */
    public function byRole(int $days): array;
}
