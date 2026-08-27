<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y tocar los centros de trabajo (regla dura 18).
 *
 * Lectura para «manager+» y escritura para «rrhh+», igual que la plantilla. La
 * escritura importa mas de lo que parece: cambiar `sites.timezone` cambia a que
 * jornada se atribuyen los tramos siguientes (RN-05), asi que es una operacion
 * con efecto sobre el computo legal y no un ajuste cosmetico.
 */
final class SitePolicy
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
