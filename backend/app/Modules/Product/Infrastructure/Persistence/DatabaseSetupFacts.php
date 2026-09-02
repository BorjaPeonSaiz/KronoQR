<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\SetupFacts;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Los hechos del asistente, en SQL (RF-PD-03).
 *
 * ## Consultas directas, sin modelo y sin clase de otro modulo
 *
 * Cinco recuentos sobre tablas que pertenecen a `Identity` y `Workforce`.
 * `Product` no puede importar esos modulos (doc 02 §1.6) y aqui no lo hace: no
 * aparece ni una clase suya, solo el esquema, que es compartido. Es el mismo
 * criterio con el que ya vive {@see DatabasePlanUsageCounter}, y los dos enums
 * que si se usan —{@see UserRole} y {@see EmploymentStatus}— viven en `Shared`
 * precisamente porque cruzan esta frontera.
 *
 * ## Estos recuentos no pueden fallar hacia arriba... pero tampoco mentir
 *
 * Al contrario que el contador del plan, que devuelve `0` ante un fallo porque
 * es un observador comercial, aqui **se deja subir la excepcion**. Un `0`
 * inventado en `hasAdministratorWithSecondFactor()` reabriria el asistente en
 * una instalacion en produccion y volveria a admitir la creacion de un primer
 * administrador publico; y un `0` en `employeesWithoutUsableCredential()` diria
 * «todo listo» el dia antes de la apertura. Ante la duda, un error visible.
 */
final readonly class DatabaseSetupFacts implements SetupFacts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function hasAdministratorWithSecondFactor(): bool
    {
        return $this->connection->table('users')
            ->where('users.is_active', true)
            // `two_factor_confirmed_at` y no `two_factor_secret`: un secreto sin
            // confirmar no autoriza nada y su titular sigue sin poder entrar
            // (RS-06). Contarlo daria el paso por hecho con el panel cerrado.
            ->whereNotNull('users.two_factor_confirmed_at')
            ->whereExists(function (Builder $query): void {
                $query->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('roles.name', UserRole::ADMIN->value);
            })
            ->exists();
    }

    public function activeEmployees(): int
    {
        return $this->connection->table('employees')
            ->where('status', EmploymentStatus::ACTIVE->value)
            ->count();
    }

    public function departments(): int
    {
        return $this->connection->table('departments')->count();
    }

    public function employeesWithoutUsableCredential(): int
    {
        return $this->connection->table('employees')
            ->where('employees.status', EmploymentStatus::ACTIVE->value)
            // «Utilizable» es exactamente lo que `CredentialLifecycleStatus`
            // llama `canClockWithCard()`: **entregada y no revocada**. Impresa y
            // sin entregar no cuenta — un PDF en una bandeja no sirve de nada a
            // las 06:00 (doc 02 §8.2), y ese es justo el aviso que el resumen
            // del asistente tiene que dar.
            ->whereNotExists(function (Builder $query): void {
                $query->from('credentials')
                    ->whereColumn('credentials.employee_id', 'employees.id')
                    ->whereNotNull('credentials.delivered_at')
                    ->whereNull('credentials.revoked_at');
            })
            ->count();
    }

    public function activeKiosks(): int
    {
        return $this->connection->table('devices')->where('status', 'active')->count();
    }
}
