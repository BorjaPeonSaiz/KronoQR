<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\Policy;

use App\Modules\Attendance\Domain\Exception\InvalidClockingPolicy;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;

/**
 * Cuando la duracion de un tramo cerrado pide revision humana: RN-07 y RN-08.
 *
 * **Recibe los umbrales por constructor, ya resueltos** (regla dura 14). No
 * hay ninguna constante aqui, ni una llamada a la configuracion: el caso de uso
 * pide los valores a `OperationalSettingsProvider` y construye la politica. Es
 * lo que permite que un hotel con turnos de 10 h y otro con turnos de 12 h se
 * atiendan con el mismo binario (ADR-017).
 *
 * **Ninguna de las dos reglas cierra ni rechaza nada.** RN-08 lo dice literal:
 * «nunca se cierra automaticamente sin intervencion humana». La politica
 * clasifica, el agregado marca el tramo, Compliance abre la incidencia al
 * recibir el evento y una persona decide. El empleado no se entera y su jornada
 * queda registrada (regla dura 19).
 */
final readonly class ClockingPolicy
{
    public function __construct(
        /** RN-07: por debajo de esto el tramo se registra igual, pero se marca. Un minuto en el perfil de serie. */
        public WorkedDuration $minimumComputableShift,
        /** RN-08: por encima de esto el tramo es anomalo. 12 h de serie, configurable por instalacion. */
        public WorkedDuration $anomalousShiftThreshold,
    ) {
        if ($minimumComputableShift->isLongerThan($anomalousShiftThreshold)) {
            throw InvalidClockingPolicy::minimumAboveAnomalous(
                $minimumComputableShift->minutes,
                $anomalousShiftThreshold->minutes,
            );
        }
    }

    /**
     * RN-07: la duracion minima computable es un umbral **estricto por abajo**.
     * Con el minimo en 1 minuto, 59 segundos truncan a 0 y son tramo corto;
     * 60 segundos son 1 minuto y ya computan.
     */
    public function isTooShort(WorkedDuration $worked): bool
    {
        return $worked->isShorterThan($this->minimumComputableShift);
    }

    /**
     * RN-08: «duracion maxima **antes** de considerarse anomalo». El umbral
     * exacto todavia no lo es; se supera al pasarlo. Con 12 h, 12:00 es normal
     * y 12:01 es anomalo.
     */
    public function isTooLong(WorkedDuration $worked): bool
    {
        return $worked->isLongerThan($this->anomalousShiftThreshold);
    }

    /**
     * Lo que tiene de raro esta duracion, si algo. Lista vacia cuando el tramo
     * es normal, que es el caso de casi todos.
     *
     * @return list<ShiftAnomaly>
     */
    public function anomaliesFor(WorkedDuration $worked): array
    {
        $anomalies = [];

        if ($this->isTooShort($worked)) {
            $anomalies[] = ShiftAnomaly::SHORT_SHIFT;
        }

        if ($this->isTooLong($worked)) {
            $anomalies[] = ShiftAnomaly::LONG_SHIFT;
        }

        return $anomalies;
    }
}
