<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Los umbrales **operativos** ya resueltos, tal como los entrega la
 * configuracion de la instalacion (`installation_settings`, RF-PD-01).
 *
 * No provienen del marco normativo: los fija el hotel. `compliance_profiles`
 * ni siquiera tiene columna para la duracion anomala de tramo, y por eso estos
 * cuatro valores llegan por un puerto distinto del de {@see CompliancePolicy}
 * (doc 01 §4, nota sobre RN-08 y RN-16).
 *
 * **Ningun valor por defecto vive aqui** (regla dura 14). Los del Anexo B del
 * doc 02 —`ATTENDANCE_MAX_SHIFT_HOURS`, `ATTENDANCE_DEBOUNCE_SECONDS`,
 * `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES`, `ATTENDANCE_MIN_TRANSIT_SECONDS`— se
 * siembran en la tarea 1.3 y se editan desde el panel en la 5.1.
 */
final readonly class OperationalSettings
{
    public function __construct(
        /** RN-08: a partir de aqui un tramo cerrado es anomalo. **Nunca se cierra solo**. */
        public int $anomalousShiftMinutes,
        /** RF-AT-06: ventana de gracia anti-rebote de un mismo empleado. */
        public int $debounceSeconds,
        /** RF-AT-10: desfase tolerado entre el reloj del quiosco y el del servidor antes de marcar incidencia. Nunca rechaza el fichaje. */
        public int $maximumClockSkewMinutes,
        /** RN-16: transito minimo creible entre dos quioscos del centro. */
        public int $minimumTransitSeconds,
    ) {
        $this->positive($anomalousShiftMinutes, 'la duracion anomala de tramo (RN-08)');
        $this->notNegative($debounceSeconds, 'la ventana anti-rebote (RF-AT-06)');
        $this->positive($maximumClockSkewMinutes, 'la tolerancia de desfase de reloj (RF-AT-10)');
        $this->notNegative($minimumTransitSeconds, 'el transito minimo entre quioscos (RN-16)');
    }

    private function positive(int $value, string $what): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException('La configuracion de la instalacion no puede fijar '.$what.' en '.$value.'.');
        }
    }

    /**
     * Cero es legitimo en los dos que desactivan una comprobacion: un centro
     * puede querer el anti-rebote apagado, o dos quioscos contiguos donde el
     * transito real es de segundos (doc 01 §4, nota sobre RN-16).
     */
    private function notNegative(int $value, string $what): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException('La configuracion de la instalacion no puede fijar '.$what.' en '.$value.'.');
        }
    }
}
