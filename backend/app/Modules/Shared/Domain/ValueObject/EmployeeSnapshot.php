<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lo que el nucleo necesita saber de un empleado para decidir un fichaje, y
 * nada mas. Es lo que devuelve el puerto `Attendance\Application\Port\
 * EmployeeDirectory`, que implementa Workforce (ADR-025).
 *
 * **Vive en Shared porque cruza la frontera entre dos modulos.** Devolver aqui
 * un modelo Eloquent o una entidad de Workforce acoplaria el nucleo al satelite
 * por el tipo de retorno, que es la forma en que la restriccion 2 de ADR-025 se
 * erosiona sin que ningun `use` de Attendance lo delate.
 *
 * `displayName` esta aqui porque RF-AT-05 obliga al quiosco a confirmar con el
 * nombre («Buenos dias, Lucia — Entrada 07:02») y `ScanAccepted` lo lleva en el
 * contrato. **No puede acabar en un log tecnico ni en `error_events`** (regla
 * dura 21): ahi se identifica al empleado por `employeeUuid` y solo por el.
 */
final readonly class EmployeeSnapshot
{
    public function __construct(
        /** UUID v7 publico del empleado (`employees.uuid`). Es el identificador con el que habla el nucleo. */
        public string $employeeUuid,
        /** `employees.employee_code`: opaco y aleatorio, nunca derivado de datos personales. */
        public string $employeeCode,
        /** Nombre para mostrar al empleado en el quiosco y en su portal. Jamas en un log. */
        public string $displayName,
        public EmploymentStatus $status,
        /** Centro al que esta adscrito. Resuelve la zona horaria de RN-05 via `SiteCalendar`. */
        public int $siteId,
        /** Departamento, si lo tiene. Es a quien se asigna la incidencia que genere este fichaje. */
        public ?int $departmentId = null,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('EmployeeSnapshot necesita el UUID del empleado.');
        }

        if ($employeeCode === '') {
            throw new InvalidArgumentException('EmployeeSnapshot necesita el codigo de empleado.');
        }

        if ($displayName === '') {
            throw new InvalidArgumentException('EmployeeSnapshot necesita un nombre para mostrar.');
        }

        if ($siteId < 1) {
            throw new InvalidArgumentException('EmployeeSnapshot necesita el centro al que esta adscrito el empleado.');
        }

        if ($departmentId !== null && $departmentId < 1) {
            throw new InvalidArgumentException('El departamento de EmployeeSnapshot, si existe, es un identificador valido.');
        }
    }

    /**
     * RN-14. Se pregunta al objeto en lugar de comparar el enum fuera: la regla
     * vive en un solo sitio y no se olvida en el segundo sitio que la use.
     */
    public function canClock(): bool
    {
        return $this->status->canClock();
    }
}
