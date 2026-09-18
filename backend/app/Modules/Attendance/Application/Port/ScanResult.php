<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;

/**
 * El desenlace detallado de un escaneo: los nueve valores de
 * `scan_events.result` (doc 01 §5.5).
 *
 * **Nunca sale por la API.** RS-03 y la regla dura 17 obligan a que el rechazo
 * que ve el quiosco sea generico y de tiempo constante. Este enum existe para
 * tres consumidores del lado del servidor —la fila de `scan_events`, la metrica
 * `scans_total{device,result}` (doc 02 §8.2) y el log estructurado— y el
 * contrato lo hace imposible de filtrar: `ScanRejected` tiene sus cuatro campos
 * clavados a un valor unico y `additionalProperties: false`.
 *
 * **Por que vive en `Application/Port/` y no en `Domain/`.** Es el vocabulario
 * que habla el puerto {@see ScanLog}, que es quien escribe la columna, y un
 * puerto solo puede hablar en tipos del dominio propio, de `Shared` o escalares
 * (ADR-025, restriccion 2): un enum declarado en `Application/` no seria
 * alcanzable desde aqui. Su mitad de rechazo ya existe en el dominio como
 * {@see ScanRejectionReason} —que es la que el dominio necesita— y este es su
 * complemento de persistencia.
 *
 * **Y la mitad de aceptacion subio en la tarea 3.5**, exactamente por el motivo
 * que este docblock dejaba escrito: `ScanIntentPolicy` y `DebouncePolicy` tienen
 * que razonar sobre `clock_in` frente a `break_start` (ADR-024), asi que esos
 * cuatro casos son ahora {@see ClockingAction} y aqui quedan `forAction()` y
 * `action()` como unico puente. Los cinco rechazos se quedan —el de RN-18 lo
 * estreno la tarea ad hoc del 18-09-2026—: de ellos el
 * dominio solo necesita el motivo, no el valor de la columna.
 */
enum ScanResult: string
{
    case CLOCK_IN = 'clock_in';

    case CLOCK_OUT = 'clock_out';

    /** RF-AT-12, estrenado por la tarea 3.5. El enum nacio completo porque la columna nacio con su CHECK completo. */
    case BREAK_START = 'break_start';

    case BREAK_END = 'break_end';

    case REJECTED_UNKNOWN = 'rejected_unknown';

    case REJECTED_REVOKED = 'rejected_revoked';

    case REJECTED_DEBOUNCE = 'rejected_debounce';

    case REJECTED_SIGNATURE = 'rejected_signature';

    /**
     * RN-18, tarea ad hoc del 18-09-2026: el escaneo llego con una hora que **no
     * puede encajar** en el registro de esa persona —ni cerrando el turno
     * abierto (RN-03) ni abriendo uno que pisaria a otro ya cerrado, en
     * cualquier jornada (RN-02)—, asi que no produjo tramo y no se va a
     * reintentar.
     *
     * Es el noveno valor de la columna y el unico rechazo que describe **el
     * registro** y no la credencial: por eso su fila queda marcada para revision
     * y abre incidencia, mientras que hacia fuera responde el mismo `422`
     * generico que los demas (RS-03).
     */
    case REJECTED_OUT_OF_ORDER = 'rejected_out_of_order';

    /**
     * El valor de columna que corresponde a lo que el dominio decidio
     * ({@see ClockingAction}, ADR-024).
     *
     * Son dos enums porque el dominio no puede nombrar `Application\Port\`
     * (ADR-025, restriccion 2) y porque describen cosas distintas: aquel dice
     * **que se hizo con la jornada** y este **que se escribio**. Anadir un
     * desenlace de persistencia —un rechazo nuevo— no obliga a tocar el dominio,
     * que es la misma razon por la que existe {@see fromRejection()}.
     */
    public static function forAction(ClockingAction $action): self
    {
        return match ($action) {
            ClockingAction::CLOCK_IN => self::CLOCK_IN,
            ClockingAction::CLOCK_OUT => self::CLOCK_OUT,
            ClockingAction::BREAK_START => self::BREAK_START,
            ClockingAction::BREAK_END => self::BREAK_END,
        };
    }

    /**
     * La accion de dominio que corresponde a este desenlace, o `null` si el
     * escaneo no produjo tramo.
     *
     * Es el camino de vuelta de {@see forAction()}, y lo usa el adaptador de
     * {@see ScanLog} para reconstruir un {@see AcceptedScan} desde la columna:
     * el dominio pregunta «¿que fue el escaneo anterior?» y la respuesta esta
     * guardada en el vocabulario de la persistencia.
     *
     * Los cinco rechazos devuelven `null`, el anti-rebote incluido: no creo
     * tramo, y por eso tampoco entra en la ventana de RF-AT-06. El de RN-18
     * tampoco, y ahi el `null` es la afirmacion mas fuerte del enum: un fichaje
     * irreconciliable **no puede** haber producido accion ninguna.
     *
     * El `match` es exhaustivo a proposito —sin `default`—: un desenlace nuevo no
     * compila hasta que alguien decida si creo tramo o no, que es justo la
     * pregunta que no se puede resolver por omision.
     */
    public function action(): ?ClockingAction
    {
        return match ($this) {
            self::CLOCK_IN => ClockingAction::CLOCK_IN,
            self::CLOCK_OUT => ClockingAction::CLOCK_OUT,
            self::BREAK_START => ClockingAction::BREAK_START,
            self::BREAK_END => ClockingAction::BREAK_END,
            self::REJECTED_UNKNOWN, self::REJECTED_REVOKED,
            self::REJECTED_DEBOUNCE, self::REJECTED_SIGNATURE,
            self::REJECTED_OUT_OF_ORDER => null,
        };
    }

    /**
     * El motivo con el que el dominio describio el rechazo, traducido al valor
     * de la columna.
     *
     * Son dos vocabularios y no uno a proposito, igual que entre `Identity` y
     * `Attendance`: el del dominio describe **por que** no se acepto; este
     * describe **que se escribio**. Anadir un desenlace de persistencia no
     * obliga a tocar el dominio.
     */
    public static function fromRejection(ScanRejectionReason $reason): self
    {
        return match ($reason) {
            ScanRejectionReason::UNKNOWN_CREDENTIAL => self::REJECTED_UNKNOWN,
            ScanRejectionReason::REVOKED_CREDENTIAL => self::REJECTED_REVOKED,
            ScanRejectionReason::INVALID_SIGNATURE => self::REJECTED_SIGNATURE,
            ScanRejectionReason::DEBOUNCE => self::REJECTED_DEBOUNCE,
            ScanRejectionReason::OUT_OF_ORDER => self::REJECTED_OUT_OF_ORDER,
        };
    }

    /**
     * Si este desenlace creo o cerro un tramo.
     *
     * Es el predicado que decide que escaneos cuentan como «ultimo fichaje
     * aceptado» para la ventana de RF-AT-06: un `rejected_debounce` no reinicia
     * la ventana, porque si lo hiciera bastaria con pasar la tarjeta cada 50
     * segundos para prolongarla indefinidamente.
     *
     * @return list<string>
     */
    public static function acceptedValues(): array
    {
        return [
            self::CLOCK_IN->value,
            self::CLOCK_OUT->value,
            self::BREAK_START->value,
            self::BREAK_END->value,
        ];
    }

    public function isAccepted(): bool
    {
        return \in_array($this->value, self::acceptedValues(), true);
    }

    public function isRejection(): bool
    {
        return ! $this->isAccepted();
    }

    /**
     * El anti-rebote es el unico desenlace que no crea tramo y **no es un
     * rechazo de cara al cliente** (ADR-031): viaja en un `200` con
     * `action: debounced`, con el nombre del empleado y el acumulado del dia,
     * porque ahi la credencial es valida y acaba de funcionar hace segundos.
     */
    public function isDebounce(): bool
    {
        return $this === self::REJECTED_DEBOUNCE;
    }
}
