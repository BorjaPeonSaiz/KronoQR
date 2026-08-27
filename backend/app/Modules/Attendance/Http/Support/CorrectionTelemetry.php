<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Support;

use App\Modules\Attendance\Application\Port\CorrectionMetrics;
use App\Modules\Attendance\Application\UseCase\CorrectedShift;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza, la metrica y el log de una correccion (doc 02 §8.1 y §8.2,
 * RF-PA-04).
 *
 * Mismo sitio y mismo motivo que {@see ScanTelemetry}: en el borde, para que el
 * `trace_id` que llega en `traceparent` sea el padre del span, para medir lo que
 * de verdad tarda el endpoint y para que el caso de uso se quede orquestando
 * reglas sin un `try/finally` de medicion alrededor.
 *
 * ## Que se cuenta y cuando
 *
 * `manual_corrections_total{reason_code}` se incrementa **solo si la correccion
 * se aplico**. Una peticion rechazada —sin permiso, con un motivo invalido,
 * sobre un tramo que otro ya corrigio— no cambio el registro horario de nadie, y
 * contarla haria que la respuesta a «¿cuanto hay que corregir a mano en esta
 * instalacion?» dependiera de cuantas veces alguien se equivoco de boton.
 *
 * ## Que lleva el log y que no
 *
 * **Ningun nombre** (regla dura 21): `employee_uuid`, `shift_entry_uuid`, la
 * accion, el motivo y quien firmo, por su identificador. Aqui el motivo **si**
 * va al log, al contrario que la causa de un rechazo de escaneo: la regla dura
 * 17 protege al empleado frente a una pantalla en un pasillo, y esto es una
 * accion de gestion que RN-13 obliga a poder explicar.
 *
 * `reason_text` **no** viaja al log. Es texto libre escrito por una persona
 * sobre otra persona: su sitio es `shift_corrections`, que tiene control de
 * acceso y retencion, no Loki ni el paquete de diagnostico (ADR-020).
 *
 * ## Medir no puede romper una correccion
 *
 * Igual que en el fichaje, y con el mismo andamiaje envuelto, {@see SpanScope}.
 * Cuando se llega a la parte de medir, la transaccion ya confirmo: el tramo esta
 * rectificado, la fila del libro escrita y el asiento de auditoria cerrado. Un
 * fallo de Redis no puede convertir eso en un `500`, porque quien corrigio
 * volveria a intentarlo y acabaria con dos correcciones.
 */
final readonly class CorrectionTelemetry
{
    public function __construct(
        private CorrectionMetrics $metrics,
        private LoggerInterface $logger,
    ) {}

    /**
     * Envuelve la aplicacion de una correccion.
     *
     * @param  string  $operation  `add`, `correct` o `void`: lo que se pidio, que no
     *                             siempre coincide con la accion que resulta —un `PATCH`
     *                             puede acabar en `closed` o en `modified`—.
     * @param  callable(): CorrectedShift  $apply
     */
    public function measure(string $operation, string $reasonCode, callable $apply): CorrectedShift
    {
        $span = SpanScope::start(
            'kronoqr.attendance',
            'attendance.'.$operation.'_shift_entry',
            SpanKind::KIND_SERVER,
            ['correction.reason_code' => $reasonCode],
        );

        try {
            $corrected = $apply();
        } catch (Throwable $failure) {
            $span->end(['correction.action' => 'error']);

            throw $failure;
        }

        $this->metrics->correctionRecorded($reasonCode);
        $span->end(['correction.action' => $corrected->action->value]);
        $this->log($corrected, $reasonCode, $span);

        return $corrected;
    }

    private function log(CorrectedShift $corrected, string $reasonCode, SpanScope $span): void
    {
        // `notice` y no `info`: una correccion manual es poco frecuente y tiene
        // relevancia legal. Que destaque sobre el ruido de los fichajes es lo
        // que permite revisar de un vistazo quien toco que la semana pasada.
        $this->logger->notice('attendance.shift_corrected', [
            'trace_id' => $span->traceId(),
            'employee_uuid' => $corrected->employeeUuid,
            'shift_entry_uuid' => $corrected->shiftEntryUuid,
            'superseded_shift_entry_uuid' => $corrected->supersededShiftEntryUuid,
            'work_date' => $corrected->workDate,
            'action' => $corrected->action->value,
            'reason_code' => $reasonCode,
            'daily_total_minutes' => $corrected->dailyTotalMinutes,
        ]);
    }
}
