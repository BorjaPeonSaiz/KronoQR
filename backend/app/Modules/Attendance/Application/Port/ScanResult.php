<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;

/**
 * El desenlace detallado de un escaneo: los ocho valores de
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
 * complemento de persistencia. Si una regla futura tuviera que razonar sobre
 * `clock_in` frente a `break_start`, el enum completo subiria a `Domain/` y esa
 * es una decision de `arquitecto-dominio`.
 */
enum ScanResult: string
{
    case CLOCK_IN = 'clock_in';

    case CLOCK_OUT = 'clock_out';

    /** RF-AT-12, tarea 3.5. El enum nace completo porque la columna nace con su CHECK completo. */
    case BREAK_START = 'break_start';

    case BREAK_END = 'break_end';

    case REJECTED_UNKNOWN = 'rejected_unknown';

    case REJECTED_REVOKED = 'rejected_revoked';

    case REJECTED_DEBOUNCE = 'rejected_debounce';

    case REJECTED_SIGNATURE = 'rejected_signature';

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
