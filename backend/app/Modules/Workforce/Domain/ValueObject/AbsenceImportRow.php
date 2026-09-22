<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Una linea del fichero de ausencias y su desenlace (**RF-GP-04**).
 *
 * **`line` cuenta la cabecera**, porque es el numero que la persona ve al abrir
 * el fichero en su hoja de calculo: la primera fila de datos es la 2. Un indice
 * base cero obligaria a sumar mentalmente en cada linea rechazada.
 *
 * **`label` es el codigo de empleado, no el nombre**, y esa es la diferencia con
 * {@see ImportRow}. Aqui el codigo basta para localizar la fila —es la columna
 * que la identifica— y no hace falta mover el nombre de nadie por un informe que
 * lleva al lado una categoria que puede ser una baja medica (regla dura 21).
 */
final readonly class AbsenceImportRow
{
    /**
     * @param  list<AbsenceImportMessage>  $messages
     */
    private function __construct(
        public int $line,
        public string $label,
        public AbsenceImportOutcome $outcome,
        /** UUID de la ausencia: la existente si `unchanged`, la creada si se aplico. */
        public ?string $absenceUuid,
        public array $messages,
        /** Lo que se registraria, o `null` en una linea rechazada. */
        public ?ImportedAbsence $absence,
    ) {}

    /**
     * @param  list<AbsenceImportMessage>  $messages
     */
    public static function rejected(int $line, string $label, array $messages): self
    {
        return new self($line, $label, AbsenceImportOutcome::REJECT, null, $messages, null);
    }

    public static function created(int $line, ImportedAbsence $absence): self
    {
        return new self($line, $absence->employeeCode, AbsenceImportOutcome::CREATE, null, [], $absence);
    }

    /** Ya existe exactamente asi: reimportar es seguro y no hay nada que hacer. */
    public static function unchanged(int $line, ImportedAbsence $absence, string $absenceUuid): self
    {
        return new self(
            $line,
            $absence->employeeCode,
            AbsenceImportOutcome::UNCHANGED,
            $absenceUuid,
            [],
            $absence,
        );
    }

    /** La misma linea, ya aplicada, con el UUID de la ausencia creada. */
    public function appliedAs(string $absenceUuid): self
    {
        return new self($this->line, $this->label, $this->outcome, $absenceUuid, $this->messages, $this->absence);
    }
}
