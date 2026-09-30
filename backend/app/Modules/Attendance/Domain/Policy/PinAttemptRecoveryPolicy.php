<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use DateTimeImmutable;

/**
 * **Cuando un PIN rechazado ya no necesita revision** (RN-19, doc 01 §4).
 *
 * Un intento queda subsanado si la misma persona tiene un fichaje que lo
 * cubre —aceptado, anti-rebote o irreconciliable, la lista la aplica el
 * puerto— con `occurred_at` dentro de `[intento, intento + 600 s]`, **ambos
 * extremos incluidos**. Lo habitual: se equivoco de PIN y al momento acerto, o
 * volvio con su tarjeta.
 *
 * La ventana es **estructural y no se configura** (doc 01 §4, nota «Sobre
 * RN-19»), como la de RN-18: no es un umbral legal ni operativo del hotel,
 * sino la definicion de «lo subsano en el acto».
 *
 * Pura: sin reloj (regla dura 2) y sin E/S. Recibe los instantes ya
 * resueltos y en UTC.
 */
final readonly class PinAttemptRecoveryPolicy
{
    /** RN-19: estructural, no se configura (doc 01 §4, nota «Sobre RN-19»). */
    public const int RECOVERY_WINDOW_SECONDS = 600;

    /**
     * @param  list<DateTimeImmutable>  $acceptedAt  `occurred_at` de fichajes que subsanan, de la
     *                                               misma persona
     */
    public function isRecovered(RejectedPinAttempt $attempt, array $acceptedAt): bool
    {
        // Se comparan instantes y no segundos enteros: `occurred_at` llega con
        // microsegundos y truncarlos daria por subsanado un fichaje a 600,4 s.
        $from = $attempt->occurredAt;
        $to = $from->modify('+'.self::RECOVERY_WINDOW_SECONDS.' seconds');

        foreach ($acceptedAt as $instant) {
            if ($instant >= $from && $instant <= $to) {
                return true;
            }
        }

        return false;
    }
}
