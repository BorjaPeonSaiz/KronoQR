<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\RosterMember;

/**
 * Quien puede fichar hoy en un centro, en la forma minima del padron
 * (RF-KI-03, doc 02 §7.3).
 *
 * **Por que en `Shared/Application/Port` y no en `Kiosk`.** Es el mismo caso que
 * {@see EmployeeRegistry} y {@see ManagementActor}, y por el mismo motivo: quien
 * **necesita** la abstraccion (`Kiosk`, que sirve el padron) y quien **tiene** el
 * dato (`Workforce`, que tiene `employees`) son dos satelites, y ninguno puede
 * importar nada del otro (§1.6, verificado por Deptrac). La unica capa que los dos
 * alcanzan es esta. El adaptador vive en `Workforce/Infrastructure/Adapter/`, que
 * es donde esta la tabla, y se enlaza en `WorkforceServiceProvider` (ADR-025,
 * restriccion 3).
 *
 * **Un metodo y una consulta.** Devuelve la lista entera del centro de una vez y
 * no un empleado por llamada: el padron de un hotel de temporada son seiscientas
 * personas, y una consulta por cada una convertiria una peticion de cache en
 * seiscientas idas a la base de datos.
 *
 * **Solo quien puede fichar** (RN-14). Quien esta de baja no aparece: darle nombre
 * en el quiosco es exactamente lo que la baja viene a impedir. Que la regla se
 * aplique aqui y no en quien llama es deliberado — el estado laboral es de
 * `Workforce` y no puede decidirse en dos sitios.
 */
interface ClockingEmployees
{
    /**
     * Los empleados del centro que pueden fichar, sin ningun orden garantizado.
     *
     * Devuelve una lista vacia si el centro no existe o no tiene a nadie. **Eso no
     * autoriza a bloquear a nadie en el quiosco** (regla dura 19, RN-15): un
     * padron vacio significa que la tablet tendra que confirmar sin nombre, no que
     * deje de aceptar fichajes.
     *
     * @return list<RosterMember>
     */
    public function atSite(int $siteId): array;
}
