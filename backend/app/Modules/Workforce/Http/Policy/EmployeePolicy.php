<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede hacer que con la plantilla (Anexo B del doc 01, regla dura 18).
 *
 * **Dos conjuntos y no uno**, tal y como los nombra el Anexo B:
 *
 *   - `manager+` puede **leer**. En la Fase 1 resuelve a `{admin, rrhh}`, porque
 *     `responsable_departamento` no tiene ambito propio hasta la tarea 2.1
 *     (RF-ID-03): darle acceso hoy seria darle la plantilla entera, que es justo
 *     lo que ese requisito viene a impedir.
 *   - `rrhh+` puede **escribir**: `{admin, rrhh}`.
 *
 * Que los dos conjuntos coincidan hoy es una coincidencia de fase, no un
 * descuido: estan escritos por separado porque en 2.1 dejan de coincidir, y una
 * sola lista obligaria a repartirla entonces, que es cuando se cometen los
 * errores.
 *
 * **La policy es la mitad de la autorizacion.** La otra es el ambito del token,
 * que comprueba el middleware `ability` antes de llegar aqui (doc 02 §7.3): un
 * token de quiosco no alcanza estas rutas aunque su portador tuviera rol, y un
 * `auditor` con `attendance:read` tampoco puede escribir aunque llegue al
 * controlador.
 */
final class EmployeePolicy
{
    /**
     * Roles que pueden leer la plantilla («manager+» del Anexo B).
     *
     * @return list<UserRole>
     */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /**
     * Roles que pueden dar de alta, modificar y dar de baja («rrhh+»).
     *
     * @return list<UserRole>
     */
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

    /**
     * Baja de empleado (RF-GP-03). Se separa de `update` porque no es lo mismo
     * corregir un apellido que cerrar el registro horario de una persona.
     */
    public function offboard(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /**
     * Restablecer el PIN (RF-ID-09). Rol RRHH, como pide el Anexo B del doc 01.
     *
     * **Permiso propio y no `update`.** Restablecer un PIN da acceso al registro
     * horario personal de otra persona (RL-05) y al fichaje de respaldo
     * (RF-AT-11): quien puede corregir un apellido no tiene por que poder
     * hacerse con la llave del portal de nadie. Que hoy los dos conjuntos
     * coincidan es una coincidencia de fase —en 2.1 dejan de hacerlo—, no un
     * motivo para escribir una sola comprobacion.
     */
    public function resetPin(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /**
     * Registrar la entrega del PIN (RF-ID-09), igual que la de la tarjeta
     * (RF-QR-06). Separada de `resetPin` porque son dos actos distintos: uno
     * genera la credencial y el otro afirma que se entrego en mano.
     */
    public function deliverPin(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }
}
