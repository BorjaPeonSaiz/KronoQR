<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use InvalidArgumentException;

/**
 * El registro horario de una persona en un rango de jornadas (RF-PA-03).
 *
 * Es lo que devuelve `GET /api/v1/employees/{uuid}/workdays` y lo que el detalle
 * de jornada del panel pinta. **Solo lee**: aqui no hay ningun metodo que cambie
 * nada, y no puede haberlo — rectificar el registro es `PATCH
 * /shift-entries/{uuid}`, que vive en `Attendance` y exige otro ambito de token.
 *
 * **Tambien es la clase con la que se autoriza.** `Gate::authorize('view',
 * WorkDayJournal::class)` resuelve `Reporting\Http\Policy\WorkDayJournalPolicy`,
 * que es como Laravel enlaza una policy con el recurso que protege. **El nombre
 * va escrito y no como `{@see}`**: Pint resuelve las referencias del docblock a
 * un `use`, y un `use` de la capa Http dentro del dominio es la regla dura 1 —
 * Deptrac lo rechaza—. Por eso el
 * recurso es esta clase de dominio y no el modelo Eloquent de otro modulo: la
 * frontera del §1.6 no concede esa arista, y una policy no deberia depender de
 * como se guardan las filas.
 *
 * `days` puede venir vacio, y no es un error: que alguien no trabajara esos dias
 * es una respuesta.
 */
final readonly class WorkDayJournal
{
    /**
     * @param  list<JournalWorkDay>  $days  De la jornada mas antigua a la mas reciente.
     */
    public function __construct(
        public string $employeeUuid,
        /** Zona del centro **actual** del empleado. La de cada tramo viaja con el tramo. */
        public string $timeZone,
        public DateRange $range,
        public array $days,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('El registro horario es siempre el de alguien.');
        }

        if ($timeZone === '') {
            throw new InvalidArgumentException('Un registro sin zona horaria obligaria al cliente a adivinarla (regla dura 3).');
        }
    }

    public function dayCount(): int
    {
        return \count($this->days);
    }
}
