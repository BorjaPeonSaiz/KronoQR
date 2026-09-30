<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use App\Modules\Compliance\Domain\Exception\UnknownIncidentType;

/**
 * Catalogo cerrado de `incidents.type` (doc 01 §5.5).
 *
 * **Es el mismo vocabulario que `Attendance\Domain\ValueObject\AnomalyType`, y
 * la duplicacion es deliberada.** `Compliance` no puede importar el dominio de
 * `Attendance` —el §1.6 no concede esa arista y Deptrac la verifica—, asi que el
 * hallazgo llega por un evento de dominio con su tipo como cadena y se traduce
 * aqui. Lo que ata las dos listas no es la buena fe: es una prueba, y el mismo
 * criterio que ya siguen `ShiftAnomaly` y `AuditAction`.
 *
 * Este catalogo es **mas ancho** que el de `Attendance` a proposito: dos de sus
 * valores no los produce la deteccion automatica.
 *
 *   - `missing_clock_out` describe el olvido de salida **ya corregido a mano**
 *     (RF-PA-04). Mientras el tramo sigue abierto, lo que hay es
 *     `open_shift_expired`.
 *   - `anomalous_pattern` es RF-PR-06 y su detector llega en la Fase 3
 *     (RN-16, tarea 3.11). Vive aqui desde ahora porque el esquema y la bandeja
 *     tienen que admitirlo sin una migracion nueva.
 */
enum IncidentType: string
{
    /** RN-08 sobre un tramo todavia abierto. El sistema NUNCA lo cierra (doc 01 §11, «Turno olvidado»). */
    case OpenShiftExpired = 'open_shift_expired';

    /** RN-07: tramo por debajo de la duracion minima computable. */
    case ShortShift = 'short_shift';

    /** RN-08 sobre un tramo cerrado, y RN-11 sobre la suma de la jornada. Los distingue `shift_entry_id`. */
    case LongShift = 'long_shift';

    /** RN-12: tramo continuo sin pausa registrada (ADR-024). */
    case MissingBreak = 'missing_break';

    /** RN-10: descanso entre jornadas por debajo del minimo legal. */
    case InsufficientRest = 'insufficient_rest';

    /** RN-15: fichaje con el reloj desviado. Se registro igual (regla dura 19). */
    case ClockSkew = 'clock_skew';

    /** Olvido de salida cerrado por correccion manual (RF-PA-04). Sin detector automatico. */
    case MissingClockOut = 'missing_clock_out';

    /** RF-PR-06 y RN-16, Fase 3. Sin detector todavia. */
    case AnomalousPattern = 'anomalous_pattern';

    /**
     * RN-18: **fichaje irreconciliable**. Un escaneo llego con una hora que no
     * podia cuadrar con el turno abierto de esa persona y quedo registrado sin
     * tramo (`rejected_out_of_order`).
     *
     * La abre la revision diaria leyendo `scan_events`, igual que `ClockSkew`, y
     * se cierra con una correccion (RN-13): crear el tramo que falta o anular lo
     * que sobra. **Sin `shift_entry_id`**, porque el escaneo no produjo ninguno.
     */
    case OutOfOrderScan = 'out_of_order_scan';

    /**
     * RN-19: **fichaje por PIN no registrado**. Alguien intento fichar en el
     * quiosco con el codigo de esta persona, el PIN no verifico —erroneo, no
     * emitido o bloqueo activo— y ningun fichaje suyo lo subsano en los 10
     * minutos siguientes.
     *
     * La abre la revision diaria leyendo `scan_events.claimed_employee_id`
     * (ADR-043), **sin `shift_entry_id`** —el intento no produjo tramo— y una
     * por persona y jornada. Se cierra con una correccion (RN-13) si la persona
     * trabajo, o se descarta con nota si no; restablecer el PIN (RF-ID-09) si
     * procede. Nada automatico.
     */
    case RejectedPinScan = 'rejected_pin_scan';

    /**
     * Con que urgencia entra en la bandeja.
     *
     * **La decide el tipo y no quien detecta**, que es lo que impide que dos
     * modulos clasifiquen el mismo hecho con distinta prioridad. `Attendance`
     * emite el hallazgo; la severidad es criterio de cumplimiento.
     *
     * El reparto sigue una sola pregunta: **que se rompe si nadie lo mira**.
     *
     *   - `high` — se ha incumplido una norma con consecuencia sancionadora
     *     (descanso entre jornadas, art. 34.3 ET) o hay un indicio sobre una
     *     persona que no puede quedarse sin revisar (RF-PR-06).
     *   - `medium` — el registro horario esta incompleto o dice algo que no
     *     cuadra, y alguien tiene que corregirlo con traza (RN-13). Es la
     *     severidad de la alerta «Turnos abiertos > 12 h» del doc 01 §9.3, y la
     *     del fichaje irreconciliable de RN-18: hay un fichaje real que **falta**
     *     en el registro, que es exactamente «incompleto y hay que corregirlo»,
     *     no una norma incumplida ni un dato meramente raro. Tambien la del
     *     fichaje por PIN no registrado de RN-19, por el mismo motivo.
     *   - `low` — el registro es valido y el dato es raro. Se mira cuando se
     *     puede.
     *
     * **`medium` es el grupo por omision y no un olvido.** Con diez tipos, un
     * `match` con un brazo por caso superaba el tope de complejidad ciclomatica
     * del §3.5, asi que se nombran los dos grupos cortos y el resto es
     * `medium`. Que un tipo nuevo no caiga ahi sin decidirlo lo impide
     * `IncidentTest`, que exige una severidad esperada para **cada** caso.
     */
    public function defaultSeverity(): IncidentSeverity
    {
        return match (true) {
            \in_array($this, [self::InsufficientRest, self::AnomalousPattern], true) => IncidentSeverity::High,
            \in_array($this, [self::ShortShift, self::ClockSkew], true) => IncidentSeverity::Low,
            default => IncidentSeverity::Medium,
        };
    }

    /**
     * El tipo que corresponde al valor que transporta el evento de dominio de
     * `Attendance`.
     *
     * Falla en vez de descartar: un hallazgo con un tipo que este catalogo no
     * conoce significa que alguien amplio el vocabulario en un modulo y no en el
     * otro, y perderlo en silencio dejaria una situacion detectada sin nadie que
     * la revise.
     */
    public static function fromDetected(string $value): self
    {
        return self::tryFrom($value) ?? throw new UnknownIncidentType($value);
    }
}
