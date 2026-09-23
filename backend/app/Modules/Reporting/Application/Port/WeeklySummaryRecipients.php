<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Quien recibe el resumen semanal (**RF-PR-05**, decision 2 de la ficha 3.12).
 *
 * Los `responsable_departamento` **activos y con direccion de correo**, uno por
 * cuenta y con su alcance ya resuelto. La lista la sirve `Identity` a traves del
 * adaptador; este modulo no importa el modelo `User` (doc 02 §1.6).
 *
 * **Una cuenta desactivada o sin direccion se omite y se cuenta**, sin log de
 * nombres (regla dura 21): lo que queda en el registro de la pasada es cuantas
 * cuentas habia y a cuantas se escribio.
 *
 * **El producto no depende del correo del empleado** (regla dura 12, ADR-015).
 * Aqui las direcciones son de cuentas de gestion, que si la tienen obligatoria;
 * ningun camino de esta funcionalidad toca `employees.email`.
 */
interface WeeklySummaryRecipients
{
    /**
     * @return list<WeeklySummaryRecipient> Ordenados por `users.id`, para que dos
     *                                      pasadas de la misma semana recorran lo
     *                                      mismo en el mismo orden.
     */
    public function active(): array;
}
