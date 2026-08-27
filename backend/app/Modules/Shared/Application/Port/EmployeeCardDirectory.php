<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * De donde salen el nombre, el departamento y el centro que se imprimen en una
 * tarjeta, y la lista de quien esta de alta para el panel de RF-QR-08.
 *
 * **Por que en `Shared/Application/Port` y no en `Identity`.** Es exactamente el
 * caso de {@see EmployeeRegistry}: quien **necesita** la abstraccion es
 * `Identity` —que posee `credentials` y es quien imprime— y quien **tiene** el
 * dato es `Workforce` —que posee `employees`, `departments` y `sites`—. Son dos
 * satelites y ninguno puede importar nada del otro (doc 02 §1.6, verificado por
 * Deptrac); la unica capa que los dos alcanzan es esta. El adaptador vive en
 * `Workforce/Infrastructure/Adapter/` y se enlaza en `WorkforceServiceProvider`
 * (ADR-025, restriccion 3).
 *
 * **Solo devuelve gente de alta.** El panel responde a «quien no puede fichar
 * todavia» y la metrica `employees_without_delivered_credential` cuenta «quienes
 * estan de alta y todavia no pueden fichar con tarjeta» (doc 02 §8.2). Incluir a
 * quien ya no trabaja aqui inflaria las dos con personas a las que no hay que
 * darles nada, y la metrica que debe llegar a cero antes de cada incorporacion
 * nunca llegaria.
 *
 * **Este puerto no decide si alguien puede fichar.** Para eso esta
 * `Attendance\Application\Port\EmployeeDirectory`, que devuelve el
 * `EmployeeSnapshot` con su `EmploymentStatus`. Duplicar aqui esa semantica
 * crearia dos sitios donde se decide lo mismo.
 */
interface EmployeeCardDirectory
{
    /**
     * Los empleados **de alta**, ordenados de forma estable, opcionalmente
     * acotados a un centro.
     *
     * El orden lo fija el adaptador y tiene que ser deterministico: es el orden
     * en el que salen las tarjetas de una hoja A4, y quien las recorta necesita
     * poder repetirlo.
     *
     * @param  int|null  $siteId  `null` = toda la instalacion.
     * @return list<EmployeeCardProfile>
     */
    public function activeProfiles(?int $siteId = null): array;

    /**
     * El perfil de un empleado por su clave interna, o `null` si no existe.
     *
     * **Incluye a quien esta de baja**, al contrario que {@see activeProfiles()}:
     * imprimir una tarjeta ya emitida a alguien cuya baja se tramito ayer no es
     * una operacion habitual, pero si ocurre debe fallar por una regla de
     * negocio explicita y no porque falte el nombre que va en el PDF.
     */
    public function profileFor(int $employeeId): ?EmployeeCardProfile;
}
