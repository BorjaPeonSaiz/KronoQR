<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * La exportacion para la Inspeccion ya escrita en el disco, contada
 * (**RF-IN-05**, RL-06, V5-PA-4).
 *
 * Es lo que `Compliance` le devuelve a `Reporting` cuando la genera en diferido:
 * el nombre del fichero y los recuentos que **el escritor** hizo mientras
 * escribia —no una estimacion previa—. Vive en `Shared` porque cruza de un
 * modulo a otro, como {@see EmployeeCardProfile}.
 *
 * ## Sin una sola fila del registro
 *
 * Ni nombres ni marcas: solo cuantas filas y cuantas personas. Lo que sale de
 * aqui acaba en `audit_log` (`legal_export.generated`), que se conserva cuatro
 * años (RL-02) y no puede ser una segunda copia de la plantilla (regla dura 21).
 */
final readonly class LegalRecordFile
{
    public function __construct(
        /** `registro-horario-<desde>_<hasta>.csv`: el de la descarga sincrona, sin nombres. */
        public string $fileName,
        public string $from,
        public string $to,
        /** Nulo para la plantilla completa. */
        public ?string $employeeUuid,
        public int $shiftEntryRows,
        public int $correctionRows,
        public int $employees,
    ) {
        if ($fileName === '') {
            throw new InvalidArgumentException('La exportacion para la Inspeccion necesita el nombre de su fichero.');
        }

        if ($shiftEntryRows < 0 || $correctionRows < 0 || $employees < 0) {
            throw new InvalidArgumentException('Los recuentos de la exportacion para la Inspeccion no pueden ser negativos.');
        }
    }

    /** Filas de datos del fichero: tramos mas correcciones. */
    public function rows(): int
    {
        return $this->shiftEntryRows + $this->correctionRows;
    }

    /**
     * El cuerpo del asiento `legal_export.generated`, **con las mismas claves
     * que escribe la descarga sincrona**: «quien saco el registro de la
     * plantilla» se tiene que contestar con una sola consulta, venga de donde
     * venga la exportacion.
     *
     * @return array<string, int|string|null>
     */
    public function auditFacts(): array
    {
        return [
            'period_from' => $this->from,
            'period_to' => $this->to,
            'scope' => $this->employeeUuid === null ? 'all' : 'employee',
            'employee_uuid' => $this->employeeUuid,
            'shift_entry_rows' => $this->shiftEntryRows,
            'correction_rows' => $this->correctionRows,
            'employees_exported' => $this->employees,
        ];
    }
}
