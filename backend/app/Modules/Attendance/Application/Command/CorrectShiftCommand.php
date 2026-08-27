<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Rectificar las marcas de un tramo (RF-PA-04, `PATCH /shift-entries/{uuid}`).
 *
 * **Nulo significa «no lo toques», nunca «borralo».** Un `PATCH` que solo trae
 * la hora de salida deja la de entrada donde estaba. No hay forma de vaciar una
 * salida ya registrada —eso seria reabrir un tramo cerrado— porque no es ninguna
 * de las cuatro acciones de RF-PA-04: un tramo que no debio cerrarse se anula y
 * se vuelve a dar de alta, y asi consta lo que paso.
 *
 * **`performedByUserId` es obligatorio y no se deduce.** RN-13 exige autor, y
 * autor es una persona de `users`, no «el sistema»: es lo que se escribe en
 * `shift_corrections.performed_by_user_id` y lo que hace defendible la
 * correccion. Por la API es quien tiene la sesion abierta.
 *
 * El motivo llega ya construido: si es `OTROS` sin explicacion de veinte
 * caracteres, `CorrectionReason` no existe y esta orden no se puede formar.
 */
final readonly class CorrectShiftCommand
{
    public function __construct(
        /** Identificador publico del tramo **vigente** que se corrige. */
        public string $shiftEntryUuid,
        /** Nueva hora de entrada en UTC, o `null` para dejarla como esta. */
        public ?DateTimeImmutable $clockedInAt,
        /** Nueva hora de salida en UTC, o `null` para dejarla como esta. Cerrar un tramo abierto es darle una. */
        public ?DateTimeImmutable $clockedOutAt,
        public CorrectionReason $reason,
        /** `users.id` de quien firma la rectificacion. */
        public int $performedByUserId,
    ) {
        if ($shiftEntryUuid === '') {
            throw new InvalidArgumentException('Correcting a shift entry needs the shift entry.');
        }

        if ($clockedInAt === null && $clockedOutAt === null) {
            throw new InvalidArgumentException('A correction must change at least one of the two marks.');
        }

        if ($performedByUserId < 1) {
            throw new InvalidArgumentException('A correction must be signed by a user (RN-13).');
        }
    }
}
