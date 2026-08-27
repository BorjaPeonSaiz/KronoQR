<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;

/**
 * La traduccion entre el UUID publico de un empleado y su clave interna, sobre
 * la tabla que la tiene.
 *
 * **Es una arista de ADR-025 y de las mas estrechas del sistema.** El puerto lo
 * declara `Shared` porque lo consume `Identity` y lo implementa `Workforce`, que
 * es quien posee `employees`. `Identity` nunca consulta esa tabla: pregunta
 * aqui.
 *
 * **Solo se leen las dos columnas de la traduccion.** No es tacaneria: este
 * adaptador se invoca en el camino de fichaje, y devolver la fila entera pondria
 * el nombre y el correo del empleado en memoria de un componente que no tiene
 * ninguna razon para verlos. Lo que no se carga no se puede filtrar por
 * descuido a un log (regla dura 21).
 */
final readonly class EloquentEmployeeRegistry implements EmployeeRegistry
{
    public function internalIdFor(string $employeeUuid): ?int
    {
        /** @var int|null $id */
        $id = Employee::query()
            ->where('uuid', $employeeUuid)
            ->value('id');

        return $id;
    }

    public function uuidOf(int $employeeId): ?string
    {
        /** @var string|null $uuid */
        $uuid = Employee::query()
            ->whereKey($employeeId)
            ->value('uuid');

        return $uuid;
    }
}
