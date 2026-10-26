<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use DateTimeImmutable;

/** Publica `management_accounts_dormant{role}` (ADR-060). */
interface DormantAccountMetrics
{
    /**
     * @param  array<string, int>  $byRole  Rol => cuentas sin uso.
     */
    public function record(array $byRole, int $days, DateTimeImmutable $at): void;
}
