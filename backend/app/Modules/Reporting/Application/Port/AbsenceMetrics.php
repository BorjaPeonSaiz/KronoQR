<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use DateTimeImmutable;

/**
 * La metrica de ausencias del cuadro «Negocio» (doc 02 §8.2, decision 8 de la
 * ficha 3.10).
 *
 * ```
 * absences_current{type}   gauge
 * ```
 *
 * ## Gauge que se recalcula entero, nunca un contador
 *
 * Describe «cuantas personas estan ausentes hoy», no «cuantas ausencias van
 * desde que se instalo». Un contador no podria corregirse cuando una ausencia se
 * anula o se corrige a otras fechas, porque solo puede crecer (regla dura 7
 * aplicada a la instrumentacion).
 *
 * ## Las cuatro etiquetas, siempre
 *
 * Tambien las que valen cero. Una serie que desaparece es indistinguible de una
 * que nunca tuvo nada, y «hoy no hay ninguna baja medica» es justo lo que se
 * mira.
 *
 * ## Sin alerta, a proposito
 *
 * Es un indicador de RRHH, no un fallo de operacion: no hay runbook que sostenga
 * avisar a nadie a las 06:30 de que alguien esta de vacaciones (doc 02 §8.4).
 */
interface AbsenceMetrics
{
    /**
     * @param  array<string, int>  $byType  los cuatro tipos, tambien los que estan a cero
     * @param  string  $onDate  fecha civil del centro medida, `AAAA-MM-DD`
     */
    public function publish(array $byType, string $onDate, DateTimeImmutable $at): void;
}
