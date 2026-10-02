<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * Las filas padre de la ficha —el centro y los departamentos—, tomadas con
 * `SELECT … FOR KEY SHARE` **antes** de la cadena de auditoria (ADR-046 §1.1,
 * punto 2, y §1.3).
 *
 * ## Por que existe
 *
 * El orden unico de candados es filas padre → cadena → `employees` →
 * `credentials`. Quien escribe una ficha con un `department_id` nuevo comprueba
 * la clave ajena con un `FOR KEY SHARE` sobre el departamento; si lo hiciera con
 * la cadena ya en la mano, esperaria a un renombrado —que toma su fila y despues
 * la cadena— y cerraria un ciclo (`40P01`). Tomandola antes, el renombrado y la
 * modificacion se ordenan sin ciclo y ninguno de los escritores de las tablas
 * padre tiene que cambiar.
 *
 * `FOR KEY SHARE` es exactamente el candado que tomaria la comprobacion de la
 * clave ajena: no bloquea a nadie que no fuera a bloquear ella.
 *
 * **Se llama dentro de una transaccion abierta** por quien llama: fuera de ella
 * el candado se soltaria al terminar la sentencia y no ordenaria nada.
 */
interface ParentRowLocks
{
    /** El centro de la instalacion (ADR-040), si existe. */
    public function shareInstallationSite(): void;

    /**
     * Los departamentos indicados, **ordenados por `id`**, para que dos
     * transacciones que piden los mismos no se crucen. Los que no existen se
     * ignoran: la clave ajena dira lo suyo al escribir.
     *
     * @param  list<int>  $departmentIds
     */
    public function shareDepartments(array $departmentIds): void;
}
