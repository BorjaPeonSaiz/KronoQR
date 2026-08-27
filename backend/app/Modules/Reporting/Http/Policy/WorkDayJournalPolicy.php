<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede leer el registro horario de otra persona (RF-PA-03, Anexo B del
 * doc 01, regla dura 18).
 *
 * ## Un solo conjunto: «manager+»
 *
 * El Anexo B lo escribe como `[rol: manager+ | self]`, y las dos mitades no se
 * resuelven en el mismo sitio:
 *
 *   - **`manager+`** es esta policy. En la Fase 1 resuelve a `{admin, rrhh}`,
 *     por lo mismo que en `Workforce\Http\Policy\EmployeePolicy` y en
 *     `Attendance\Http\Policy\ShiftEntryPolicy` —escritas asi y no como
 *     `{@see}` porque Pint resolveria la referencia a un `use`, y un `use` de
 *     otro modulo es la frontera del §1.6 que Deptrac rechaza—:
 *     `responsable_departamento` no tiene alcance propio hasta la tarea 2.1
 *     (RF-ID-03), y darselo hoy seria darle el registro horario de **toda** la
 *     instalacion, que es justo lo que ese requisito viene a impedir.
 *   - **`self`** no entra por aqui. El propio empleado consulta lo suyo en
 *     `GET /api/v1/me/workdays`, con sesion de portal y ambito `self:read`
 *     (ADR-015, RF-ID-07, tarea 1.11), que es lo que el contrato dice en la
 *     descripcion de este endpoint. Un token de portal ni siquiera alcanza esta
 *     ruta: le falta `attendance:read`, que el middleware `ability` comprueba
 *     antes. **No hay aqui una rama «o es el mismo empleado»** porque no habria
 *     forma de llegar a ella: una comprobacion inalcanzable no protege nada y
 *     ademas no se puede probar.
 *
 * ## Por que el `auditor` no esta, teniendo el ambito
 *
 * `auditor` lleva `attendance:read` en su token (catalogo de roles) y aun asi
 * recibe `403` aqui. No es una contradiccion: es exactamente para lo que sirven
 * las dos comprobaciones del doc 02 §7.3. El Anexo B no lo pone en `manager+`, y
 * lo que ese rol necesita —el registro para un requerimiento— se sirve por
 * `GET /api/v1/reports/legal-export`, que es suyo (`auditor|rrhh`, tarea 1.17) y
 * queda auditado como exportacion legal, no como consulta de pantalla.
 *
 * ## La policy es la mitad de la autorizacion
 *
 * La otra es el ambito `attendance:read` del token, que verifica el middleware
 * `ability` antes de llegar aqui. Con las dos, un token de quiosco no alcanza
 * este endpoint aunque su portador tuviera rol —no tiene el ambito— y una cuenta
 * con el ambito pero sin rol tampoco lee el registro de nadie.
 *
 * **Se registra contra {@see WorkDayJournal},
 * que es un objeto de dominio y no un modelo Eloquent.** Asi la autorizacion se
 * decide **antes** de tocar la base de datos: si se declarara sobre una fila,
 * habria que cargarla para poder preguntar si se puede leer.
 */
final class WorkDayJournalPolicy
{
    /**
     * Roles que pueden consultar el registro horario de un tercero («manager+»).
     *
     * Metodo y no constante porque en la tarea 2.1 deja de ser una lista fija:
     * `responsable_departamento` entrara con alcance por departamento, y el
     * alcance se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /**
     * `GET /api/v1/employees/{uuid}/workdays`.
     */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }
}
