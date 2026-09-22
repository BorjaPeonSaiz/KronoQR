<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\ValueObject;

/**
 * Un motivo de rechazo o un aviso sobre una linea del fichero de ausencias
 * (**RF-GP-04**).
 *
 * **Codigo y columna, nunca texto y nunca el valor de la celda**, con el mismo
 * criterio que {@see ImportMessage}: el dominio no sabe en que idioma se va a
 * leer el informe, y «la baja de `E7QK2MXPR` del 3 al 10 solapa» seria un dato
 * personal en un objeto que acaba en una respuesta y podria acabar en un log
 * (regla dura 21).
 */
final readonly class AbsenceImportMessage
{
    private function __construct(
        public AbsenceImportMessageCode $code,
        public ?string $column,
    ) {}

    public static function of(AbsenceImportMessageCode $code, ?string $column = null): self
    {
        return new self($code, $column);
    }

    public function isWarning(): bool
    {
        return $this->code->isWarning();
    }
}
