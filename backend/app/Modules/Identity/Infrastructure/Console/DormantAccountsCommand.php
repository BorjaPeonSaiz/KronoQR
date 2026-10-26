<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Port\DormantAccountMetrics;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\DormantManagementAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * `php artisan identity:dormant-accounts` — cuenta las cuentas de gestion
 * activas sin uso y publica `management_accounts_dormant{role}` (ADR-060).
 *
 * **No desactiva ni borra nada.** Una cuenta activa no se purga: puede ser de
 * alguien de baja medica o de vacaciones. Lo que hace falta es que alguien lo
 * vea; lo ve `product:doctor` y lo ve la metrica.
 *
 * La salida son recuentos por rol, nunca cuentas (regla dura 21): acaba en el
 * log del planificador.
 */
final class DormantAccountsCommand extends Command
{
    protected $signature = 'identity:dormant-accounts';

    protected $description = 'Cuenta las cuentas de gestion activas sin uso y publica management_accounts_dormant';

    public function handle(DormantManagementAccounts $accounts, DormantAccountMetrics $metrics, Clock $clock): int
    {
        $days = max(1, Config::integer('identity.dormant_account_days', 90));
        $byRole = $accounts->byRole($days);

        $metrics->record($byRole, $days, $clock->now());

        $this->line(\sprintf(
            '%d cuenta(s) de gestion activa(s) sin entrar en mas de %d dias%s.',
            array_sum($byRole),
            $days,
            $byRole === [] ? '' : ' ('.implode(', ', array_map(
                static fn (string $role, int $count): string => $role.': '.$count,
                array_keys($byRole),
                $byRole,
            )).')',
        ));

        return self::SUCCESS;
    }
}
