<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Alta manual de un tramo que nunca se ficho (RF-PA-04, `POST /shift-entries`).
 *
 * **La jornada se declara, no se deduce.** Podria derivarse de la fecha civil de
 * la entrada, pero eso seria adivinar en el unico caso en que importa: la vuelta
 * de una pausa a las 02:30 pertenece a la jornada que empezo ayer a las 22:00
 * (ADR-024), y derivarla la mandaria al dia siguiente partiendo el turno de
 * noche por la puerta de atras (RN-05, ADR-006, regla dura 4). Quien da de alta
 * el tramo esta mirando el detalle de una jornada concreta en el panel
 * (RF-PA-03) y sabe a cual lo esta anadiendo.
 *
 * La zona en la que esa fecha es civil la resuelve el caso de uso con
 * `SiteCalendar`, a partir del centro del empleado. Aqui viaja como `YYYY-MM-DD`
 * porque es lo que hay en `shift_entries.work_date` y lo que manda la API.
 */
final readonly class AddShiftEntryCommand
{
    public function __construct(
        public string $employeeUuid,
        /** Jornada a la que se atribuye, en forma `YYYY-MM-DD` (RN-05). */
        public string $workDate,
        public DateTimeImmutable $clockedInAt,
        /** Salida, o `null` si el tramo se da de alta abierto. */
        public ?DateTimeImmutable $clockedOutAt,
        public CorrectionReason $reason,
        /** `users.id` de quien firma el alta. */
        public int $performedByUserId,
    ) {
        if ($employeeUuid === '') {
            throw new InvalidArgumentException('A manual shift entry needs the employee.');
        }

        if ($performedByUserId < 1) {
            throw new InvalidArgumentException('A correction must be signed by a user (RN-13).');
        }
    }
}
