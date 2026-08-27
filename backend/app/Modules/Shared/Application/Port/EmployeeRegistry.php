<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * Traduce entre los dos identificadores de un empleado: el **publico**
 * (`employees.uuid`), que es el unico que viaja por la API y por la auditoria, y
 * el **interno** (`employees.id`), que es el que declaran las claves ajenas de
 * las tablas de otros modulos.
 *
 * **Por que existe.** `credentials.employee_id` es una clave ajena a
 * `employees`, tabla que pertenece a `Workforce`. `Identity` —que es quien posee
 * `credentials`— necesita esa traduccion para poder emitir una credencial a
 * partir del UUID que llega en la peticion, y para poder decir de quien es la
 * tarjeta que se acaba de escanear. Sin este puerto solo quedaban dos salidas, y
 * las dos malas: que `Identity` consultara la tabla de otro modulo —lo que el
 * doc 02 §1.6 prohibe explicitamente— o duplicar el UUID del empleado en
 * `credentials`, que es desnormalizar el esquema para esquivar una frontera de
 * codigo.
 *
 * **Por que en `Shared/Application/Port` y no en `Identity`.** Es el mismo caso
 * que {@see ManagementActor}, y por el mismo motivo: quien **necesita** la
 * abstraccion (`Identity`) y quien **tiene** el dato (`Workforce`) son dos
 * satelites, y ninguno puede importar nada del otro (§1.6, verificado por
 * Deptrac). La unica capa que los dos alcanzan es `Shared/Application/Port`, que
 * es donde ADR-021 situa los contratos que cruzan modulos sin ser regla de
 * negocio de ninguno. El adaptador vive en `Workforce/Infrastructure/Adapter/`,
 * que es donde esta la tabla, y se enlaza en `WorkforceServiceProvider`
 * (ADR-025, restriccion 3).
 *
 * **Dos metodos y ni uno mas.** Este puerto **no** contesta si el empleado puede
 * fichar ni como se llama: para eso ya esta
 * `Attendance\Application\Port\EmployeeDirectory`, que devuelve un
 * `EmployeeSnapshot` completo. Duplicar aqui esa semantica crearia dos sitios
 * donde se decide lo mismo, y el dia que RN-14 cambie solo se acordaria uno.
 * Aqui se traducen identificadores y nada mas.
 */
interface EmployeeRegistry
{
    /**
     * Clave interna del empleado con ese UUID, o `null` si no existe.
     *
     * **Incluye a quien esta de baja**: dar de baja no borra la ficha (regla
     * dura 5) y sus credenciales siguen teniendo que poder revocarse y
     * consultarse.
     */
    public function internalIdFor(string $employeeUuid): ?int;

    /**
     * UUID publico del empleado con esa clave interna, o `null` si no existe.
     *
     * Es el sentido que usa el camino de fichaje: la fila de `credentials` sabe
     * el `employee_id` y quien la resuelve necesita el UUID, que es el unico
     * identificador de persona admitido en un log tecnico (regla dura 21).
     */
    public function uuidOf(int $employeeId): ?string;
}
