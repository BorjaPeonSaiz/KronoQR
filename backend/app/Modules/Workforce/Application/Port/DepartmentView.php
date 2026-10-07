<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Model\Department;

/**
 * Un departamento tal como lo ve el panel: el modelo de dominio y su
 * responsable (RF-ID-03, RF-ID-10).
 *
 * **El responsable no esta en el modelo de dominio** ({@see Department}): es una
 * referencia a una cuenta de gestion, que es de otro modulo, y ninguna regla de
 * `Workforce` lo usa. Por eso viaja aqui, en la lectura, y no en el agregado.
 *
 * **Se devuelve aunque la cuenta este dada de baja.** El departamento se
 * comporta entonces como sin responsable —el alcance y los avisos filtran por
 * `is_active`—, pero quien lo interpreta es el panel, que lo señala; ocultarlo
 * aqui dejaria al `admin` sin saber que hay que asignar otro.
 *
 * `managerName` es `users.name`: puede salir en la API, nunca en un log tecnico
 * ni en `audit_log` (regla dura 21), donde va el uuid.
 */
final readonly class DepartmentView
{
    public function __construct(
        public Department $department,
        public ?string $managerUserUuid = null,
        public ?string $managerName = null,
    ) {}
}
