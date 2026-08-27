<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un asiento del libro de correcciones, visto desde el detalle de jornada
 * (`shift_corrections`, RN-13, RL-04, RF-PA-03).
 *
 * **Lleva las marcas completas de las dos versiones y no un delta**, igual que
 * la fila de la que sale: es lo que permite pintar el «de → a» del panel sin
 * volver a consultar `shift_entries`, y lo que permite reconstruir el historico
 * de una jornada cuyos tramos ya no son vigentes.
 *
 * `before` es nulo en un alta —antes no habia tramo— y `after` en una anulacion
 * —no hay version posterior de un hecho que no paso—. Que falten es
 * **significativo**, y por eso la clase se niega a construirse sin ninguno de
 * los dos: una correccion que no describe ningun cambio es una fila que miente.
 */
final readonly class JournalCorrection
{
    public function __construct(
        /** La version del tramo que la accion produjo, o la que termino si fue una anulacion. */
        public string $shiftEntryUuid,
        /** `created`, `modified`, `closed` o `voided`: los cuatro verbos de RF-PA-04. */
        public string $action,
        /** Momento de la CORRECCION, que no es ninguna de las marcas del tramo. */
        public DateTimeImmutable $performedAt,
        public CorrectionAuthor $performedBy,
        /** Codigo del catalogo cerrado del Anexo C del documento 01. */
        public string $reasonCode,
        public ?string $reasonText,
        public ?ShiftMarks $before,
        public ?ShiftMarks $after,
    ) {
        if ($shiftEntryUuid === '') {
            throw new InvalidArgumentException('Una correccion se refiere siempre a un tramo.');
        }

        if ($action === '') {
            throw new InvalidArgumentException('Una correccion declara que se hizo con el tramo.');
        }

        if ($reasonCode === '') {
            throw new InvalidArgumentException('Una correccion sin motivo dice que las horas cambiaron y no dice por que (RN-13).');
        }

        if (! $before instanceof ShiftMarks && ! $after instanceof ShiftMarks) {
            throw new InvalidArgumentException('Una correccion sin marcas anteriores ni posteriores no describe ningun cambio.');
        }
    }
}
