<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y tocar los departamentos (regla dura 18).
 *
 * Cuando la tarea 2.1 traiga RF-ID-03, esta es una de las policies que gana
 * ambito: un `responsable_departamento` vera el suyo y no los demas. Hoy no
 * existe ese rol con alcance, asi que no aparece en ninguna de las dos listas.
 */
final class DepartmentPolicy
{
    /** @return list<UserRole> */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /** @return list<UserRole> */
    private static function writers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    public function viewAny(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }

    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }

    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    public function update(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }
}
