<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;
use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\UnprocessableScanVerdict;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * **Cuando deja el servidor de intentar un fichaje que no consigue procesar**
 * (RN-22, ADR-061, que enmienda ADR-047).
 *
 * Hasta ADR-061, un fichaje que fallaba siempre se devolvia `503` para
 * siempre y, por RN-21, detenia la cola entera del quiosco. Ahora se descarta
 * —con incidencia para revision humana, nunca registrandolo— cuando se cumplen
 * **las tres condiciones a la vez**:
 *
 * 1. **Al menos `minAttempts` intentos contados**, el actual incluido. Protege
 *    del quiosco que estuvo horas sin red y solo lo intento dos veces.
 * 2. **Al menos `minSpanMinutes` entre el primer intento contado y el actual**,
 *    ambos con el reloj del servidor. Da margen a que alguien arregle un
 *    defecto pasajero antes de que cueste trabajo a RRHH. El borde cuenta: a
 *    los 60 minutos justos ya se cumple.
 * 3. **El quiosco no ha agotado su cupo** de descartes del servidor en las
 *    ultimas {@see self::BUDGET_WINDOW_HOURS} horas. Un veneno de verdad es
 *    excepcional; si el siguiente fichaje tambien falla, el problema es el
 *    servidor, y descartar uno por hora vaciaria el registro automatico.
 *
 * El cupo **se mira el ultimo**: solo cuando ya habria descarte, para que
 * {@see UnprocessableScanVerdict::BudgetExhausted} signifique exactamente «se
 * habria descartado y no se puede», que es lo que dispara la alerta critica.
 *
 * Un reloj del servidor corregido hacia atras (el instante actual anterior al
 * primer fallo) no tiene ventana cumplida: se sigue reintentando. Ante la duda,
 * conservar.
 *
 * Pura: sin reloj (regla dura 2), sin configuracion (regla dura 14) y sin E/S.
 * El instante lo da el puerto `Clock` en la capa de aplicacion, y el umbral, el
 * puerto `ScanDiscardSettings`.
 */
final readonly class UnprocessableScanPolicy
{
    /** Ventana del cupo por quiosco. Estructural, no se configura: es la que nombra la alerta. */
    public const int BUDGET_WINDOW_HOURS = 24;

    /**
     * @param  int  $serverDiscardsInWindow  descartes del servidor de este quiosco desde
     *                                       {@see self::budgetWindowStartsAt()}
     */
    public function decide(
        ScanFailureHistory $history,
        int $serverDiscardsInWindow,
        DateTimeImmutable $now,
        ScanDiscardThreshold $threshold,
    ): UnprocessableScanVerdict {
        TimeRange::assertUtc('now', $now);

        if ($serverDiscardsInWindow < 0) {
            throw new InvalidArgumentException(
                'Los descartes de un quiosco en la ventana del cupo no pueden ser negativos, y han llegado '
                .$serverDiscardsInWindow.'.'
            );
        }

        if ($threshold->isDisabled()) {
            return UnprocessableScanVerdict::KeepRetrying;
        }

        if ($history->attempts < $threshold->minAttempts) {
            return UnprocessableScanVerdict::KeepRetrying;
        }

        $spanReachedAt = $history->firstFailedAt->modify('+'.$threshold->minSpanMinutes.' minutes');

        if ($now < $spanReachedAt) {
            return UnprocessableScanVerdict::KeepRetrying;
        }

        if ($serverDiscardsInWindow >= $threshold->dailyBudget) {
            return UnprocessableScanVerdict::BudgetExhausted;
        }

        return UnprocessableScanVerdict::Discard;
    }

    /**
     * Desde cuando cuentan los descartes del cupo. El adaptador que los cuenta
     * usa este instante y no uno propio: dos definiciones de «las ultimas 24 h»
     * acabarian discrepando justo en el borde.
     */
    public function budgetWindowStartsAt(DateTimeImmutable $now): DateTimeImmutable
    {
        TimeRange::assertUtc('now', $now);

        return $now->modify('-'.self::BUDGET_WINDOW_HOURS.' hours');
    }
}
