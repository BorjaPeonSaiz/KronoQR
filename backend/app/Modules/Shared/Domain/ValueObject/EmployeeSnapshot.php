<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use DateTimeImmutable;
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
        /**
         * Fecha civil de alta (`employees.hired_at`, `Y-m-d`). La usa el alta
         * manual de tramos para saber que jornadas se le pueden anotar a una
         * persona de baja (RN-14, 2.2.0). El fichaje no la mira.
         */
        public ?string $hiredOn = null,
        /** Fecha civil de cese (`employees.terminated_at`, `Y-m-d`), o `null` si no esta de baja. */
        public ?string $terminatedOn = null,
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

        self::assertCivilDate('hiredOn', $hiredOn);
        self::assertCivilDate('terminatedOn', $terminatedOn);

        // `Y-m-d` ordena igual como cadena que como fecha. Es la misma regla que
        // `employees_chk_terminated_after_hired`: una instantanea que la rompiera
        // describiria una ficha que la base no admite.
        if ($hiredOn !== null && $terminatedOn !== null && $terminatedOn < $hiredOn) {
            throw new InvalidArgumentException('La fecha de cese de EmployeeSnapshot no puede ser anterior a la de alta.');
        }
    }

    /**
     * Una fecha civil `Y-m-d` que existe en el calendario, o nada.
     *
     * La vuelta a texto es lo que caza `2026-02-31`, que `createFromFormat`
     * desborda a marzo sin quejarse.
     */
    private static function assertCivilDate(string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('EmployeeSnapshot::'.$field.' tiene que ser una fecha civil Y-m-d valida.');
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
