<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Port\DormantAccounts;
use Illuminate\Database\ConnectionInterface;

/**
 * Cuentas activas sin entrar al panel desde hace mas de N dias: `last_login_at`
 * o, si nunca entraron, `created_at`. El mismo criterio que la comprobacion
 * `access.dormant_accounts` de `product:doctor`.
 */
final readonly class DatabaseDormantAccounts implements DormantAccounts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function byRole(int $days): array
    {
        /** @var list<object{role: string, accounts: int|string}> $rows */
        $rows = $this->connection->select(
            <<<'SQL'
                SELECT r.name AS role, count(DISTINCT u.id) AS accounts
                  FROM users u
                  JOIN model_has_roles mhr ON mhr.model_id = u.id
                  JOIN roles r ON r.id = mhr.role_id
                 WHERE u.is_active = true
                   AND COALESCE(u.last_login_at, u.created_at) < now() - make_interval(days => ?)
                 GROUP BY r.name
                 ORDER BY r.name
                SQL,
            [max(1, $days)],
        );

        $byRole = [];

        foreach ($rows as $row) {
            $byRole[$row->role] = (int) $row->accounts;
        }

        return $byRole;
    }
}
