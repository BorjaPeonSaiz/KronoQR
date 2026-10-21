<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Metrics;

use App\Modules\Identity\Application\Port\DormantAccountMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

/**
 * `kronoqr_management_accounts.prom` (ADR-060, tarea 11.3 B). Una serie por cada
 * rol de gestion, tambien a cero: una serie que desaparece no se distingue de
 * una tarea que dejo de correr.
 */
final readonly class TextfileDormantAccountMetrics implements DormantAccountMetrics
{
    private const string FILE = 'kronoqr_management_accounts.prom';

    public function record(array $byRole, int $days, DateTimeImmutable $at): void
    {
        $lines = [
            '# HELP management_accounts_dormant Cuentas de gestion activas que llevan mas de IDENTITY_DORMANT_ACCOUNT_DAYS dias sin entrar al panel, por rol. No se purgan: alguien decide si darlas de baja.',
            '# TYPE management_accounts_dormant gauge',
        ];

        foreach (UserRole::cases() as $role) {
            if (! $role->isManagementRole()) {
                continue;
            }

            $lines[] = 'management_accounts_dormant{role="'.TextfileExposition::escapeLabel($role->value).'"} '
                .($byRole[$role->value] ?? 0);
        }

        $lines[] = '# HELP management_accounts_dormant_days Dias sin entrar a partir de los que una cuenta cuenta como sin uso.';
        $lines[] = '# TYPE management_accounts_dormant_days gauge';
        $lines[] = 'management_accounts_dormant_days '.$days;
        $lines[] = '# HELP management_accounts_dormant_check_timestamp_seconds Momento del ultimo recuento.';
        $lines[] = '# TYPE management_accounts_dormant_check_timestamp_seconds gauge';
        $lines[] = 'management_accounts_dormant_check_timestamp_seconds '.$at->getTimestamp();

        TextfileExposition::write(self::FILE, $lines);
    }
}
