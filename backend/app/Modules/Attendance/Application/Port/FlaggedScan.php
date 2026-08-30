<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * Un escaneo que quedo **marcado para revision** (`scan_events.flagged_for_review`),
 * tal y como lo devuelve {@see FlaggedScans}.
 *
 * La marca la puso `ReviewPolicy` en el momento del fichaje por una de dos
 * razones —origen PIN (RF-AT-11) o desfase de reloj (RN-15)— y lleva el desfase
 * medido. La revision diaria vuelve a preguntar **solo por la segunda**: una
 * incidencia `clock_skew` afirma que el reloj estaba desviado, y eso no es
 * cierto de un fichaje por PIN con la hora en punto.
 *
 * **Lleva el desfase, no la conclusion.** Quien decide si supera el umbral es el
 * dominio, con el valor vigente del centro (regla dura 14): si el adaptador
 * filtrara por umbral, la regla acabaria escrita en una consulta SQL.
 *
 * **Cuatro campos y ni uno mas.** Nacio con siete —`scan_id`, `site_id` y
 * `occurred_at` ademas de estos— y ninguno de los tres lo leia nadie: el centro
 * es uno por instalacion (ADR-040) y lo resuelve el caso de uso, y el momento y
 * el identificador del escaneo no caben en `incidents.context`, que el contrato
 * declara como **enteros y nada mas** —es una garantia de privacidad, no un
 * detalle de tipos—. Quien revise un `clock_skew` llega al escaneo que lo
 * origino por el tramo y la jornada, que si viajan: `scan_events` esta indexada
 * por `(employee_id, occurred_at)`. Un campo que nadie lee es un campo que
 * alguien acaba leyendo mal.
 */
final readonly class FlaggedScan
{
    public function __construct(
        public string $employeeUuid,
        /**
         * Desfase medido, **con signo**: el reloj del quiosco puede ir adelantado
         * o atrasado. Nulo cuando no se midio —un fichaje manual desde el panel—,
         * y entonces no hay nada que evaluar.
         */
        public ?int $clockSkewSeconds,
        /**
         * Jornada del tramo que este escaneo abrio o cerro, en forma
         * `YYYY-MM-DD`. Nula cuando el escaneo no produjo tramo.
         */
        public ?string $workDate,
        /** Identificador publico del tramo, si lo hubo. */
        public ?string $shiftEntryUuid,
    ) {}
}
