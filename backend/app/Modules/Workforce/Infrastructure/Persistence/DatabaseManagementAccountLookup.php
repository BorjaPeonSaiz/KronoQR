<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Port\EligibleManager;
use App\Modules\Workforce\Application\Port\ManagementAccountLookup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * {@see ManagementAccountLookup} sobre `users` y las tablas de roles.
 *
 * **Por nombre de tabla y columnas, sin el modelo de `Identity`** (doc 02 §1.6,
 * Deptrac): la misma licencia que se toman `Compliance` y `Product` con esa
 * tabla.
 *
 * **Sin candado de fila.** Se llama con la cadena de auditoria tomada, y la
 * baja de una cuenta la toma antes de tocar su fila (ADR-051 §6): es la cadena
 * la que ordena los dos cambios. Un `FOR SHARE` aqui pondria a esperar a
 * cualquier `UPDATE` de `users` que no pase por la cadena —el ultimo acceso,
 * por ejemplo— sin proteger nada mas.
 *
 * **Sin filtrar `model_type`**, como `DatabaseSetupFacts` de `Product`: el rol
 * `responsable_departamento` solo lo tienen cuentas de gestion.
 */
final readonly class DatabaseManagementAccountLookup implements ManagementAccountLookup
{
    public function __construct(private ConnectionInterface $connection) {}

    public function eligibleManager(string $uuid): ?EligibleManager
    {
        /** @var object{id: int|string, uuid: string}|null $row */
        $row = $this->connection->table('users')
            ->where('users.uuid', strtolower($uuid))
            ->where('users.is_active', true)
            ->whereExists(function (Builder $query): void {
                $query->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('roles.name', UserRole::RESPONSABLE_DEPARTAMENTO->value);
            })
            ->first(['users.id', 'users.uuid']);

        return $row === null ? null : new EligibleManager((int) $row->id, $row->uuid);
    }
}
