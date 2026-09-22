<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/**
 * Lo que hace falta para registrar una ausencia (**RF-GP-04**).
 *
 * Las fechas llegan como cadenas ISO tal y como vinieron en la peticion: el
 * `FormRequest` ya ha comprobado que son fechas, y convertirlas es del caso de
 * uso, que es quien sabe que una ausencia es calendario y no un instante.
 *
 * **No lleva `status`, `version` ni nada del encadenado.** Los escribe el
 * servidor: dejarlos entrar permitiria registrar una ausencia ya anulada, que
 * es un estado que nadie sabria interpretar seis meses despues.
 */
final readonly class RegisterAbsenceCommand
{
    public function __construct(
        /** UUID publico de la persona. Va en el cuerpo y no en la ruta: `/absences` es de primer nivel. */
        public string $employeeUuid,
        public AbsenceType $type,
        /** Primer dia, `YYYY-MM-DD`, inclusive. */
        public string $startsOn,
        /** Ultimo dia, `YYYY-MM-DD`, inclusive. */
        public string $endsOn,
        /** Obligatoria si el tipo la exige. **Nunca un diagnostico** (regla dura 21). */
        public ?string $note = null,
        /**
         * Cuenta de gestion que la registra. `null` en una semilla o en una
         * carga sin sesion detras: ahi no hay nadie, y forzar un autor obligaria
         * a inventar una cuenta de sistema.
         */
        public ?int $registeredByUserId = null,
    ) {}
}
