<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\AccessHardeningFacts;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\PinLength;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Los recuentos de {@see AccessHardeningFacts}, en SQL de solo lectura.
 *
 * Mismo criterio que {@see DatabaseSetupFacts}: tablas de `Identity` y
 * `Workforce` leidas por su esquema, sin importar una sola clase de esos
 * modulos (doc 02 §1.6, Deptrac). Los enums que cruzan la frontera viven en
 * `Shared` precisamente para esto.
 *
 * Si la consulta falla, **se deja subir**: `product:doctor` convierte la
 * excepcion en un fallo de la familia `access` con su clase y su remedio
 * ({@see RunDoctorHandler}). Un `0`
 * inventado diria «no queda ningun PIN corto» el dia que no se pudo contar.
 */
final readonly class DatabaseAccessHardeningFacts implements AccessHardeningFacts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function activeEmployeesWithPinOf(PinLength $length): int
    {
        return $this->connection->table('employees')
            ->where('status', EmploymentStatus::ACTIVE->value)
            ->whereNotNull('pin_hash')
            ->where('pin_length', $length->value)
            ->count();
    }

    public function activeAccountsWithoutSecondFactor(array $roles): int
    {
        if ($roles === []) {
            return 0;
        }

        $names = array_map(static fn (UserRole $role): string => $role->value, $roles);

        return $this->connection->table('users')
            ->where('users.is_active', true)
            // Confirmado y no solo con secreto: un alta a medias no autoriza
            // nada y deja la ventana tan abierta como sin secreto (RS-06).
            ->whereNull('users.two_factor_confirmed_at')
            ->whereExists(function (Builder $query) use ($names): void {
                $query->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->whereIn('roles.name', $names);
            })
            ->count();
    }
}
