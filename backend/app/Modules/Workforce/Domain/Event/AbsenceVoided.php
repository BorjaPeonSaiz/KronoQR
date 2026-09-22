<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha anulado una ausencia: se declara que **no ocurrio** (**RF-GP-04**,
 * regla dura 5).
 *
 * ## Por que se audita aparte de la correccion
 *
 * Anular devuelve esos dias al absentismo no justificado, que es el sentido
 * contrario del que tiene registrar. Con una sola accion en el catalogo, la
 * consulta «¿que ausencias se han quitado este mes y quien las quito?» habria
 * que responderla filtrando por el contenido del JSON en lugar de por la columna
 * indexada, que es justo lo que un catalogo cerrado existe para evitar.
 *
 * **No hay version nueva**, y por eso este evento no lleva `supersedesUuid`: de
 * un hecho que no paso no hay version posterior. El `uuid` es el de la misma
 * fila, que sigue en la tabla con todo lo que tenia.
 */
final readonly class AbsenceVoided implements DomainEvent
{
    public function __construct(
        public string $absenceUuid,
        public string $employeeUuid,
        public string $type,
        public string $startsOn,
        public string $endsOn,
        /** La de la fila anulada; **no sube** al anular. */
        public int $version,
        /** Si llevaba nota. **Nunca su contenido** (regla dura 21). */
        public bool $hasNote,
        /** Por que se anula, tal y como lo escribio quien lo hizo. */
        public string $reason,
        private DateTimeImmutable $occurredAt,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'workforce.absence_voided';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
