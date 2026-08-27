<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede rectificar el registro horario de otra persona (RF-PA-04, RN-13,
 * regla dura 18).
 *
 * **Dos conjuntos, y aqui no coinciden por casualidad** (plan 1.15, paso 6):
 *
 *   - **`manager+` crea y corrige.** Dar de alta un tramo olvidado y ajustar una
 *     hora son operaciones del dia a dia de quien lleva un turno.
 *   - **`rrhh+` anula.** Anular saca horas del registro de una persona y tiene
 *     efecto directo en su nomina. No es la misma responsabilidad que ajustar
 *     diez minutos, y por eso no es el mismo permiso.
 *
 * En la Fase 1 los dos resuelven a `{admin, rrhh}`, porque
 * `responsable_departamento` no tiene alcance propio hasta la tarea 2.1
 * (RF-ID-03): darselo hoy seria darle el registro horario de toda la
 * instalacion, que es justo lo que ese requisito viene a impedir. **Estan
 * escritos por separado porque en 2.1 dejan de coincidir**, y una sola lista
 * obligaria a repartirla entonces, que es cuando se cometen los errores.
 *
 * **La policy es la mitad de la autorizacion.** La otra es el ambito
 * `attendance:correct` del token, que comprueba el middleware `ability` antes de
 * llegar aqui (doc 02 §7.3). Con las dos, un token de quiosco no alcanza estos
 * endpoints aunque su portador tuviera rol —no tiene el ambito— y un `auditor`
 * con `attendance:read` tampoco puede escribir aunque llegue al controlador.
 *
 * **Un empleado no corrige nada, ni siquiera lo suyo.** Su token lleva
 * `self:read` y su rol no esta en ninguna de las dos listas: las dos mitades le
 * dicen que no. Si pudiera, el registro horario dejaria de ser un registro y
 * pasaria a ser una declaracion.
 */
final class ShiftEntryPolicy
{
    /**
     * Roles que pueden dar de alta y corregir tramos («manager+» del Anexo B).
     *
     * @return list<UserRole>
     */
    private static function correctors(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /**
     * Roles que pueden anular («rrhh+»).
     *
     * @return list<UserRole>
     */
    private static function voiders(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /**
     * `POST /api/v1/shift-entries` — alta manual de un tramo que nunca se ficho.
     */
    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::correctors());
    }

    /**
     * `PATCH /api/v1/shift-entries/{uuid}` — rectificar las marcas.
     */
    public function correct(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::correctors());
    }

    /**
     * `POST /api/v1/shift-entries/{uuid}/void` — anular.
     *
     * Permiso propio y no `correct`, por lo dicho arriba: es la accion mas grave
     * de las cuatro de RF-PA-04.
     */
    public function void(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::voiders());
    }
}
