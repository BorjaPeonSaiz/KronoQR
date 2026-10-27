<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinStatus;
use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use DateTimeImmutable;

/**
 * **Cuando un PIN rechazado ya no necesita revision** (RN-19, doc 01 §4).
 *
 * Tres decisiones de la regla, las tres aqui y ninguna en un adaptador
 * (R3-AR-04 de la re-verificacion 2.2.0):
 *
 * 1. **Que fichajes subsanan** ({@see self::recovers()}): cualquiera aceptado,
 *    el anti-rebote y el irreconciliable de RN-18. El adaptador consulta esa
 *    lista, no la escribe.
 * 2. **Cuando subsanan** ({@see self::isRecovered()}): con `occurred_at`
 *    dentro de `[intento, intento + 600 s]`, **ambos extremos incluidos**. Lo
 *    habitual: se equivoco de PIN y al momento acerto, o volvio con su tarjeta.
 * 3. **Cuando se puede juzgar** ({@see self::statusOf()}): solo con la ventana
 *    cerrada. Mientras sigue abierta, la subsanacion puede no haber ocurrido
 *    todavia, y abrir la incidencia seria adelantarse (R3-AR-03, R3-BE-02).
 *
 * La ventana es **estructural y no se configura** (doc 01 §4, nota «Sobre
 * RN-19»), como la de RN-18: no es un umbral legal ni operativo del hotel,
 * sino la definicion de «lo subsano en el acto».
 *
 * Pura: sin reloj (regla dura 2) y sin E/S. Recibe los instantes ya
 * resueltos y en UTC, tambien el de la pasada.
 */
final readonly class PinAttemptRecoveryPolicy
{
    /** RN-19: estructural, no se configura (doc 01 §4, nota «Sobre RN-19»). */
    public const int RECOVERY_WINDOW_SECONDS = 600;

    /**
     * Si un fichaje con este desenlace subsana un PIN rechazado de la misma
     * persona.
     *
     * - **Cualquier accion aceptada**: la persona ficho.
     * - **El anti-rebote** (ADR-031): la persona ficho y el servidor lo
     *   reconocio como repetido.
     * - **El irreconciliable de RN-18**: la persona ficho y su propia
     *   incidencia ya lo lleva a revision.
     * - **Ningun otro rechazo**: una tarjeta desconocida, revocada, con mala
     *   firma o de una persona que no puede fichar no dice que esa persona
     *   fichara.
     *
     * Es una lista de los que si, y no de los que no: un motivo de rechazo nuevo
     * no subsana hasta que alguien lo decida aqui, que es el lado seguro —como
     * mucho, una incidencia de mas que revisa una persona—.
     */
    public static function recovers(ClockingAction|ScanRejectionReason $outcome): bool
    {
        return $outcome instanceof ClockingAction
            || \in_array($outcome, [ScanRejectionReason::DEBOUNCE, ScanRejectionReason::OUT_OF_ORDER], true);
    }

    /** El ultimo instante en que un fichaje todavia subsana el intento, incluido. */
    public function windowEndsAt(RejectedPinAttempt $attempt): DateTimeImmutable
    {
        return $attempt->occurredAt->modify('+'.self::RECOVERY_WINDOW_SECONDS.' seconds');
    }

    /**
     * @param  list<DateTimeImmutable>  $acceptedAt  `occurred_at` de fichajes que subsanan
     *                                               ({@see self::recovers()}), de la misma persona
     */
    public function isRecovered(RejectedPinAttempt $attempt, array $acceptedAt): bool
    {
        // Se comparan instantes y no segundos enteros: `occurred_at` llega con
        // microsegundos y truncarlos daria por subsanado un fichaje a 600,4 s.
        $from = $attempt->occurredAt;
        $to = $this->windowEndsAt($attempt);

        foreach ($acceptedAt as $instant) {
            if ($instant >= $from && $instant <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * El estado del intento en el instante `$now` de la pasada.
     *
     * La subsanacion se mira primero: si ya existe, el intento esta subsanado
     * aunque su ventana siga abierta. Sin ella, el intento **espera** mientras
     * `$now` no haya pasado del final de la ventana —el extremo es del intento:
     * a `+600 s` exactos un fichaje todavia subsana— y despues queda sin
     * subsanar.
     *
     * @param  list<DateTimeImmutable>  $acceptedAt
     */
    public function statusOf(RejectedPinAttempt $attempt, array $acceptedAt, DateTimeImmutable $now): RejectedPinStatus
    {
        TimeRange::assertUtc('now', $now);

        if ($this->isRecovered($attempt, $acceptedAt)) {
            return RejectedPinStatus::RECOVERED;
        }

        if ($now <= $this->windowEndsAt($attempt)) {
            return RejectedPinStatus::PENDING;
        }

        return RejectedPinStatus::UNRECOVERED;
    }
}
