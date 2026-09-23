<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\CredentialPatternPolicy;
use App\Modules\Attendance\Domain\Policy\ReviewPolicy;
use InvalidArgumentException;

/**
 * Los cuatro numeros con los que {@see CredentialPatternPolicy} decide, **ya
 * resueltos** (RF-PR-06, RN-16, regla dura 14).
 *
 * Ninguno esta escrito aqui: los fija el hotel en `installation_settings`
 * —`ATTENDANCE_PATTERN_WINDOW_SECONDS`, `ATTENDANCE_PATTERN_MIN_REPEATS`,
 * `ATTENDANCE_MIN_TRANSIT_SECONDS` y `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES`— y los
 * sirve `OperationalSettingsProvider` al caso de uso, que es quien construye
 * esto. El dominio no consulta configuracion; la recibe.
 *
 * El cuarto no es un umbral del patron: es el de RN-15/RF-AT-10, y entra aqui
 * porque RN-16 lo necesita para saber que horas estan en duda (decision 16 de la
 * ficha 3.11). Viaja por este objeto y no por una constante para que la
 * politica pueda construir la MISMA {@see ReviewPolicy}
 * que uso el quiosco al registrar el escaneo: una segunda copia de esa
 * comparacion seria la forma segura de que el fichaje marcara un desfase que la
 * deteccion no considera desviado, o al reves.
 *
 * **Los 120 s del transito son una decision de producto, no una medicion**
 * (doc 01 §4, nota sobre RN-16, 13-08-2026): razonables como punto de partida y
 * absurdos en un resort de distancias grandes. Por eso son configuracion por
 * instalacion, y por eso el cero tiene significado propio.
 *
 * ## El cero apaga, y apagar es legitimo
 *
 * `windowSeconds = 0` desactiva la coincidencia de quiosco y
 * `minTransitSeconds = 0` la secuencia imposible —ya documentado asi en
 * `configuracion.md` §2.1 para un centro con dos tablets contiguas en la misma
 * puerta—. No es un valor invalido que haya que corregir en silencio: es la
 * forma de decir «esta comprobacion no aplica en mi centro».
 *
 * `minRepeats` no admite cero: «sistematico» con cero repeticiones no significa
 * nada, y un uno abriria incidencia ante la primera coincidencia, que es lo
 * contrario de lo que RF-PR-06 pide. El catalogo lo acota entre 1 y 30.
 */
final readonly class CredentialPatternThresholds
{
    public function __construct(
        /** RF-PR-06: separacion por debajo de la cual dos escaneos en el mismo quiosco coinciden. */
        public int $windowSeconds,
        /** RF-PR-06: dias con coincidencia que tiene que acumular un par antes de que haya hallazgo. */
        public int $minRepeats,
        /** RN-16: transito minimo creible entre dos quioscos del centro. */
        public int $minTransitSeconds,
        /**
         * RN-15/RF-AT-10: desfase de reloj tolerado, en minutos. Por encima de
         * el, la hora del escaneo esta en duda y RN-16 no se evalua sobre ella.
         */
        public int $maximumClockSkewMinutes,
    ) {
        $this->notNegative($windowSeconds, 'la ventana de coincidencia (RF-PR-06)');
        $this->notNegative($minTransitSeconds, 'el transito minimo entre quioscos (RN-16)');
        $this->notNegative($maximumClockSkewMinutes, 'la tolerancia de desfase de reloj (RF-AT-10)');

        if ($minRepeats < 1) {
            throw new InvalidArgumentException(
                'Las coincidencias necesarias para abrir incidencia (RF-PR-06) son al menos una, y han llegado '
                .$minRepeats.'. Para desactivar el hallazgo, pon la ventana a cero.'
            );
        }
    }

    /** Sin ventana no hay coincidencia que medir: el hallazgo queda apagado. */
    public function coincidenceIsDisabled(): bool
    {
        return $this->windowSeconds === 0;
    }

    /** Sin transito minimo no hay imposibilidad que afirmar: el hallazgo queda apagado. */
    public function impossibleSequenceIsDisabled(): bool
    {
        return $this->minTransitSeconds === 0;
    }

    /**
     * RF-PR-06: si dos escaneos en el mismo quiosco estan lo bastante juntos.
     *
     * **Estricto**, como el resto de umbrales del modulo (`ReviewPolicy`,
     * `DebouncePolicy`, la restriccion de exclusion de RN-02): con 10 s de
     * ventana, 9 s cuenta y 10 s no. Un limite que pertenece a los dos lados se
     * comporta distinto segun quien lo evalue.
     */
    public function isCoincidence(int $gapSeconds): bool
    {
        return $gapSeconds < $this->windowSeconds;
    }

    /**
     * RN-16: si el hueco entre dos quioscos distintos no da tiempo a recorrerlos.
     *
     * Estricto por el mismo motivo: con 120 s, 119 s es imposible y 120 s es
     * justo lo que el centro considera creible.
     */
    public function isImpossibleTransit(int $gapSeconds): bool
    {
        return $gapSeconds < $this->minTransitSeconds;
    }

    /** RF-PR-06: si un par de personas ya acumula bastantes dias con coincidencia. */
    public function isSystematic(int $coincidenceDays): bool
    {
        return $coincidenceDays >= $this->minRepeats;
    }

    private function notNegative(int $value, string $what): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                'La configuracion de la instalacion no puede fijar '.$what.' en '.$value.'.'
            );
        }
    }
}
