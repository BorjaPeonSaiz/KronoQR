<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Una linea del fichero y su desenlace (**RF-GP-05**).
 *
 * **`line` cuenta la cabecera**, porque es el numero que la persona ve al abrir
 * el fichero en su hoja de calculo: la primera fila de datos es la 2. Un indice
 * base cero obligaria a sumar mentalmente en cada linea rechazada.
 *
 * **`changes` nunca contiene `hired_at`.** Una importacion no reescribe la fecha
 * de alta de nadie (regla dura 5); si el fichero trae otra, se dice con el aviso
 * {@see ImportMessageCode::HIRED_AT_NOT_UPDATED} y no se aplica.
 */
final readonly class ImportRow
{
    /**
     * @param  list<string>  $changes
     * @param  list<ImportMessage>  $messages
     */
    private function __construct(
        public int $line,
        public string $label,
        public ImportOutcome $outcome,
        public ?string $employeeUuid,
        public array $changes,
        public array $messages,
        public ?ImportedEmployee $employee,
    ) {}

    /**
     * @param  list<ImportMessage>  $messages
     */
    public static function rejected(int $line, string $label, array $messages): self
    {
        return new self($line, $label, ImportOutcome::REJECT, null, [], $messages, null);
    }

    /**
     * @param  list<ImportMessage>  $messages
     */
    public static function created(int $line, ImportedEmployee $employee, array $messages = []): self
    {
        return new self($line, $employee->label(), ImportOutcome::CREATE, null, [], $messages, $employee);
    }

    /**
     * @param  list<string>  $changes
     * @param  list<ImportMessage>  $messages
     */
    public static function matched(
        int $line,
        ImportedEmployee $employee,
        string $employeeUuid,
        array $changes,
        array $messages = [],
    ): self {
        return new self(
            $line,
            $employee->label(),
            // Sin cambios es `unchanged` y no `update`: decir «actualizada» de
            // una fila que no se toca haria imposible ver, en el informe de una
            // reimportacion, cual es la linea que de verdad cambia.
            $changes === [] ? ImportOutcome::UNCHANGED : ImportOutcome::UPDATE,
            $employeeUuid,
            $changes,
            $messages,
            $employee,
        );
    }

    /** La misma linea, ya aplicada, con el UUID de la persona creada. */
    public function appliedAs(string $employeeUuid): self
    {
        return new self(
            $this->line,
            $this->label,
            $this->outcome,
            $employeeUuid,
            $this->changes,
            $this->messages,
            $this->employee,
        );
    }
}
