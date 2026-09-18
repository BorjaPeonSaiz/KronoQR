<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\Model\WorkDay;
use DateTimeImmutable;

/**
 * Un escaneo registrado como **fichaje irreconciliable** (RN-18): la fila de
 * `scan_events` con `result = 'rejected_out_of_order'`, tal y como la devuelve
 * {@see OutOfOrderScans}.
 *
 * Es el hecho, no la conclusion. Aqui no hay ninguna comparacion contra la
 * entrada del turno: esa la hizo el agregado en el momento del fichaje
 * ({@see WorkDay::outOfOrderScanFor()}) y su
 * veredicto quedo escrito en la columna. Volver a decidirlo esta noche —con un
 * registro que para entonces puede haber cambiado por una correccion— daria dos
 * respuestas distintas sobre el mismo escaneo.
 *
 * **Tres campos, y los tres se leen.** El empleado agrupa; el `scan_id` y el
 * `occurred_at` son lo que viaja al `context` de la incidencia, que es lo unico
 * con lo que una persona encuentra ese fichaje en el log para corregirlo. **Ni
 * un nombre ni un codigo de empleado** (regla dura 21): un UUID y un instante no
 * identifican a nadie por si solos.
 *
 * No trae el tramo abierto que provoco el rechazo, y es deliberado: la
 * incidencia de RN-18 no señala ningun tramo —dice «revisa esta jornada»— y un
 * campo que nadie lee es un campo que alguien acaba leyendo mal
 * ({@see FlaggedScan}).
 */
final readonly class RejectedOutOfOrderScan
{
    public function __construct(
        /** Identificador publico del empleado cuyo fichaje no se pudo cuadrar. */
        public string $employeeUuid,
        /** `scan_events.scan_id`: el UUID v7 que genero la tablet (regla dura 8). */
        public string $scanId,
        /**
         * El momento **real** del escaneo, nunca el de recepcion (regla dura 9).
         * Es el que decide a que jornada pertenece el hallazgo y el que una
         * persona necesita para saber que fichaje estaba intentando registrar.
         */
        public DateTimeImmutable $occurredAt,
    ) {}
}
