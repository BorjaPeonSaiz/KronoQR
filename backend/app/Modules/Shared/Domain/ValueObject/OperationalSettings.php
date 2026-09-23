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
 * valores llegan por un puerto distinto del de {@see CompliancePolicy}
 * (doc 01 §4, nota sobre RN-08 y RN-16).
 *
 * **Ningun valor por defecto vive aqui** (regla dura 14). Los del Anexo B del
 * doc 02 —`ATTENDANCE_MAX_SHIFT_HOURS`, `ATTENDANCE_DEBOUNCE_SECONDS`,
 * `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES`, `ATTENDANCE_MIN_TRANSIT_SECONDS`— se
 * siembran en la tarea 1.3 y se editan desde el panel en la 5.1;
 * `ATTENDANCE_BREAK_CLOCKING` se anade en la 3.5, y
 * `ATTENDANCE_PATTERN_WINDOW_SECONDS` y `ATTENDANCE_PATTERN_MIN_REPEATS` en la
 * 3.11, y `KIOSK_UPDATE_WINDOW` y `KIOSK_UPDATE_QUIET_MINUTES` en la 3.12.
 *
 * **Las dos ultimas no las consume el servidor**: viajan al quiosco por el
 * latido (RF-KI-07) y quien decide con ellas es la tablet. Entran igualmente por
 * aqui porque son configuracion operativa del centro y porque el latido ya
 * resuelve este objeto para llevarse el fichaje de pausa y la tolerancia de
 * desfase; un puerto aparte para dos claves que se leen en el mismo sitio y en
 * el mismo momento habria sido una segunda cascada que mantener.
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
        /**
         * RF-PR-06: separacion por debajo de la cual dos fichajes de personas
         * distintas en el mismo quiosco cuentan como una coincidencia
         * (`ATTENDANCE_PATTERN_WINDOW_SECONDS`, tarea 3.11).
         *
         * Cero la desactiva, como el transito minimo y por lo mismo: es la forma
         * de decir «esta comprobacion no aplica en mi centro».
         */
        public int $patternWindowSeconds,
        /**
         * RF-PR-06: dias con coincidencia que acumula un par de personas antes
         * de que se abra la incidencia (`ATTENDANCE_PATTERN_MIN_REPEATS`).
         *
         * Al menos uno. Cero no significa nada: para apagar el hallazgo se pone
         * la ventana a cero.
         */
        public int $patternMinRepeats,
        /**
         * RF-AT-12: si el quiosco ofrece fichar la pausa en esta instalacion
         * (`ATTENDANCE_BREAK_CLOCKING`, ADR-024).
         *
         * Gobierna **dos cosas y no tres**: la pantalla de la tablet —el boton
         * «Pausa» solo aparece con esto activado— y la evaluacion de RN-12
         * ({@see ComplianceRuleSuspension}), que abre `missing_break` solo donde
         * la plantilla ficha la pausa. **No gobierna si el servidor honra la
         * intencion declarada**: eso se hace siempre (decision 1 de la ficha
         * 3.5), porque `intent` es lo que la persona pidio y ese hecho no
         * depende de un ajuste.
         *
         * **Sin valor por defecto**, como los otros cuatro: el valor de serie
         * —`disabled`— vive en el catalogo de `SettingKey`, lo siembra la
         * migracion y lo sirve `OperationalSettingsProvider` (regla dura 14). Un
         * `= false` aqui seria una segunda fuente para el mismo dato, y la que
         * ganaria en silencio el dia que el adaptador se olvidara de leer la
         * clave: el hotel activaria la pausa en el panel y el quiosco seguiria
         * sin ofrecerla.
         */
        public bool $breakClockingEnabled,
        /**
         * RF-KI-07: la franja en la que una tablet puede aplicar una version
         * nueva de la PWA (`KIOSK_UPDATE_WINDOW`, tarea 3.12).
         *
         * **No gobierna al servidor en absoluto**: viaja al quiosco por el
         * latido y la decision es suya. Esta aqui —y no en un puerto propio—
         * porque es configuracion operativa del centro, como las seis de arriba,
         * y porque el latido ya resuelve este objeto para llevarse los otros dos
         * ajustes de la tablet.
         */
        public KioskUpdateWindow $kioskUpdateWindow,
        /**
         * RF-KI-07: minutos **sin ningun escaneo** que la tablet exige ademas de
         * la franja antes de aplicar (`KIOSK_UPDATE_QUIET_MINUTES`).
         *
         * Cero es legitimo y la desactiva: entonces mandan la franja y la cola
         * vacia. Cubre el turno que entra antes de lo previsto sin obligar al
         * producto a saber cuando empieza (paso 6 de la ficha 3.12).
         */
        public int $kioskUpdateQuietMinutes,
    ) {
        $this->positive($anomalousShiftMinutes, 'la duracion anomala de tramo (RN-08)');
        $this->notNegative($debounceSeconds, 'la ventana anti-rebote (RF-AT-06)');
        $this->positive($maximumClockSkewMinutes, 'la tolerancia de desfase de reloj (RF-AT-10)');
        $this->notNegative($minimumTransitSeconds, 'el transito minimo entre quioscos (RN-16)');
        $this->notNegative($patternWindowSeconds, 'la ventana de coincidencia en quiosco (RF-PR-06)');
        $this->positive($patternMinRepeats, 'los dias con coincidencia que abren incidencia (RF-PR-06)');
        // Cero es legitimo, como el anti-rebote: apaga la guarda de silencio y
        // deja mandar a la franja y a la cola vacia (RF-KI-07).
        $this->notNegative($kioskUpdateQuietMinutes, 'los minutos sin escaneo antes de actualizar el quiosco (RF-KI-07)');
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
