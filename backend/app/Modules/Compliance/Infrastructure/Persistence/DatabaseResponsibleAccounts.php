<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\ResponsibleAccounts;
use Illuminate\Database\ConnectionInterface;

/**
 * Si una cuenta de gestion existe y esta activa (`users.is_active`), para
 * firmar una revision de purga (decision D7 del bloque 5 de la 2.2.1).
 *
 * Lee `users` por el mismo motivo que `Audit\ManagementUserDirectory`: el dueño
 * de `audit_log.actor_id` puede comprobar a quien apunta. No mira el rol: lo
 * que se exige es una persona que responda, igual que `--responsible` de
 * `compliance:apply-retention`.
 */
final readonly class DatabaseResponsibleAccounts implements ResponsibleAccounts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function isActiveManagementAccount(int $userId): bool
    {
        return $this->connection->table('users')
            ->where('id', $userId)
            ->where('is_active', true)
            ->exists();
    }
}
