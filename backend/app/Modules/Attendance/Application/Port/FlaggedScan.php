<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

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
 */
final readonly class FlaggedScan
{
    public function __construct(
        public string $scanId,
        public string $employeeUuid,
        public int $siteId,
        /** Momento real del escaneo, el del dispositivo (regla dura 9). */
        public DateTimeImmutable $occurredAt,
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
