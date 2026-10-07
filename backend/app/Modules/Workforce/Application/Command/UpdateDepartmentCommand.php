<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Cambio de un departamento: su nombre, su responsable, o las dos cosas
 * (RF-GP-01, RF-ID-03, RF-ID-10).
 *
 * No incluye el centro a proposito: mover un departamento de centro arrastraria
 * a sus empleados a otra zona horaria (RN-05).
 *
 * **`managerGiven` distingue «sin cambio» de «sin responsable».** En el cuerpo
 * del `PATCH`, la ausencia de `manager_user_uuid` deja al responsable como
 * esta y `null` lo quita; un unico `?string` no puede decir las dos cosas.
 */
final readonly class UpdateDepartmentCommand
{
    public function __construct(
        public int $id,
        public ?string $name = null,
        public bool $managerGiven = false,
        public ?string $managerUserUuid = null,
    ) {}
}
