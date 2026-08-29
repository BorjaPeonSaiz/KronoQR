<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\ShiftEntrySubject;
use App\Modules\Shared\Application\Port\EmployeeRegistry;

/**
 * {@see ShiftEntrySubject} sobre `shift_entries`.
 *
 * Una consulta por indice unico que trae **una sola columna**, la clave interna
 * del empleado, y una traduccion a UUID por el puerto que ya existe para eso
 * ({@see EmployeeRegistry}, ADR-025). Aqui no se rehidrata ningun tramo: nadie va
 * a operar sobre el, solo se necesita saber de quien es para decidir si quien
 * pregunta lo alcanza.
 *
 * **La tabla `employees` no se consulta desde aqui.** `Attendance` no puede
 * (doc 02 §1.6), y no le hace falta: la traduccion entre los dos identificadores
 * de una persona ya tiene su puerto, y usarlo es mas barato que abrir una
 * frontera.
 */
final readonly class EloquentShiftEntrySubject implements ShiftEntrySubject
{
    public function __construct(private EmployeeRegistry $employees) {}

    public function employeeUuidOf(string $shiftEntryUuid): ?string
    {
        /** @var int|null $employeeId */
        $employeeId = ShiftEntry::query()
            ->where('uuid', $shiftEntryUuid)
            ->value('employee_id');

        return $employeeId === null ? null : $this->employees->uuidOf($employeeId);
    }
}
