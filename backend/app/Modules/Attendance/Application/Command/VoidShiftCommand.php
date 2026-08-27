<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use InvalidArgumentException;

/**
 * Anular un tramo: declarar que no ocurrio (RF-PA-04, `POST
 * /shift-entries/{uuid}/void`, ADR-026).
 *
 * Es la accion mas grave de las cuatro y por eso el plan la reserva a **rrhh+**
 * mientras corregir la hora es de manager+: borra horas del registro de una
 * persona. No borra la fila —regla dura 5— pero si la saca del conjunto que
 * cuenta, y eso tiene efecto en su nomina.
 *
 * No lleva marcas: no hay nada que rectificar. Solo hace falta saber cual, quien
 * y por que.
 */
final readonly class VoidShiftCommand
{
    public function __construct(
        public string $shiftEntryUuid,
        public CorrectionReason $reason,
        /** `users.id` de quien firma la anulacion. */
        public int $performedByUserId,
    ) {
        if ($shiftEntryUuid === '') {
            throw new InvalidArgumentException('Voiding a shift entry needs the shift entry.');
        }

        if ($performedByUserId < 1) {
            throw new InvalidArgumentException('A correction must be signed by a user (RN-13).');
        }
    }
}
