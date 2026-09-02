<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver, crear y modificar el centro de la instalacion (ADR-040).
 *
 * Sin `viewAny`: no hay lista, y no la habra.
 *
 * ## `create` existe desde la tarea 5.5, y es de `admin` y de nadie mas
 *
 * Crear el centro es la puesta en marcha (`POST /api/v1/setup/site`) y ocurre
 * **una vez en la vida de la instalacion**: fija la zona horaria con la que se
 * atribuiran todas las jornadas (RN-05) y deja asiento en `audit_log`. Es la
 * misma potestad que el resto del asistente, que el §7.3 concede unicamente al
 * administrador de instalacion.
 *
 * **`rrhh` si puede modificarlo y no puede crearlo**, y la asimetria es
 * deliberada: modificar el nombre del hotel es mantenimiento y crear el centro
 * es constituir la instalacion. Ademas, quien ejecuta el asistente es el primer
 * administrador por definicion —es la unica cuenta que existe en ese momento—,
 * asi que abrirlo a `rrhh` no habilitaria ningun caso real.
 */
final class SitePolicy
{
    /**
     * @return list<UserRole>
     */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /**
     * @return list<UserRole>
     */
    private static function writers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }

    /** Alta del centro: solo la puesta en marcha, y solo `admin` (RF-PD-03). */
    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(UserRole::ADMIN);
    }

    public function update(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }
}
