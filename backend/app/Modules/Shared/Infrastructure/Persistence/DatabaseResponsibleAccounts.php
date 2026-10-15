<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * {@see ResponsibleAccounts} sobre `users` y las tablas de roles.
 *
 * **Por nombre de tabla y columnas, sin el modelo de `Identity`** (doc 02 §1.6,
 * Deptrac): la misma licencia que se toman `Compliance` y `Product` con esa
 * tabla.
 *
 * **Sin candado de fila.** Quien firma un asiento la llama con la cadena de
 * `audit_log` tomada, y la baja de una cuenta toma la cadena antes de tocar su
 * fila (ADR-051 §6): es la cadena la que ordena los dos cambios.
 *
 * **Sin filtrar `model_type`**, como `DatabaseManagementAccountLookup`: los
 * roles de gestion solo los tienen cuentas de gestion.
 */
final readonly class DatabaseResponsibleAccounts implements ResponsibleAccounts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function isActiveManagementAccount(int $userId, array $roles = []): bool
    {
        $query = $this->connection->table('users')
            ->where('users.id', $userId)
            ->where('users.is_active', true);

        if ($roles !== []) {
            $names = array_map(static fn (UserRole $role): string => $role->value, $roles);

            $query->whereExists(function (Builder $exists) use ($names): void {
                $exists->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->whereIn('roles.name', $names);
            });
        }

        return $query->exists();
    }
}
