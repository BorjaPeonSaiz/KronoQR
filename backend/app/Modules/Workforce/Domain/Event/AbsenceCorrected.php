<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha corregido una ausencia: hay una version nueva y la anterior queda como
 * historico (**RF-GP-04**, **RN-13**, regla dura 5).
 *
 * ## Lleva el ANTES completo, no solo el despues
 *
 * `previousType`, `previousStartsOn` y `previousEndsOn` son lo que convierte el
 * asiento en algo **reconstruible**. Con solo el estado final, responder «¿quien
 * cambio esta baja de tres dias a diez y por que?» obligaria a reconstruir la
 * cadena de versiones desde la primera. Es el mismo criterio con el que
 * {@see EmploymentContractRegistered} lleva `previousWeeklyHours`: un asiento
 * que no permite reconstruir el cambio describe un estado, no un hecho.
 *
 * ## `reason` si, `note` no
 *
 * El motivo del cambio es lo que RN-13 exige conservar y es texto que quien
 * corrige escribe **sobre el cambio**, no sobre la persona. La nota es otra cosa:
 * puede llevar un diagnostico, asi que de ella solo viaja `hasNote` (regla dura
 * 21). Ni la nueva ni la anterior entran en el asiento.
 */
final readonly class AbsenceCorrected implements DomainEvent
{
    public function __construct(
        /** UUID de la version **nueva**, que es la vigente a partir de ahora. */
        public string $absenceUuid,
        /** UUID de la version a la que sustituye: la que venia en la ruta. */
        public string $supersedesUuid,
        public string $employeeUuid,
        public string $type,
        public string $startsOn,
        public string $endsOn,
        /** La de la version nueva. Empieza en 2. */
        public int $version,
        /** Si la version nueva lleva nota. **Nunca su contenido**. */
        public bool $hasNote,
        public string $previousType,
        public string $previousStartsOn,
        public string $previousEndsOn,
        /** Por que se corrigio, tal y como lo escribio quien lo hizo. */
        public string $reason,
        private DateTimeImmutable $occurredAt,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'workforce.absence_corrected';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
