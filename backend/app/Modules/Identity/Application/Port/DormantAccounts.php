<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

/**
 * Cuantas cuentas de gestion activas llevan sin entrar mas de N dias, por rol
 * (ADR-060, tarea 11.3 B). Solo numeros: ni quienes ni sus correos.
 */
interface DormantAccounts
{
    /**
     * @return array<string, int> Rol => cuentas, sin los roles que no tienen ninguna.
     */
    public function byRole(int $days): array;
}
