<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\AbsenceMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

/**
 * Publica `absences_current{type}` para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2, decision 8 de la ficha 3.10).
 *
 * ```
 * absences_current{type="sick_leave"}
 * ```
 *
 * **Por fichero y no por `/metrics`**, igual que su hermana de cumplimiento y
 * por lo mismo: quien produce estos numeros es un comando programado que corre y
 * termina, y un contador en memoria de un proceso que termina no lo lee nadie.
 * El doc 02 §8.2 lo dice ademas por escrito para esta serie.
 *
 * **Se escriben los cuatro tipos, tambien los que estan a cero.** Una serie que
 * desaparece es indistinguible de una que nunca tuvo nada, y «hoy no hay ninguna
 * baja medica» es justo lo que se mira.
 *
 * **Una sola serie, sin la hermana de «que dia se midio».** La de cumplimiento
 * publica ademas `compliance_metrics_week_start_seconds` para que unas cifras
 * congeladas no se lean como una semana tranquila, y aqui haria el mismo papel —
 * pero el §8.2 declara **solo** `absences_current{type}`, y una serie que el
 * documento no nombra es exactamente el hueco que `MetricsCatalogueTest` existe
 * para cerrar. El dia que se quiera, se añade la fila al §8.2 primero; la fecha
 * medida entra mientras tanto por `{@see AbsenceMetrics::publish()}` y se queda
 * en el log del comando.
 *
 * **Ni un nombre, ni un `employee_uuid`, ni un departamento** (regla dura 21).
 * El tipo de ausencia es dato de salud cuando dice «baja medica», y aqui va sin
 * sujeto: cuatro numeros del centro entero.
 *
 * La mecanica de escritura —guard del colector, escritura atomica, fallo ruidoso
 * y escapado— es de {@see TextfileExposition}.
 */
final readonly class TextfileAbsenceMetrics implements AbsenceMetrics
{
    private const string FILE = 'kronoqr_absences.prom';

    public function publish(array $byType, string $onDate, DateTimeImmutable $at): void
    {
        $lines = [
            '# HELP absences_current Personas de alta con una ausencia registrada que cubre el dia de hoy en la zona del centro, por tipo (RF-GP-04). No lleva ninguna etiqueta que identifique a una persona.',
            '# TYPE absences_current gauge',
        ];

        // Orden estable: un fichero que cambia de orden en cada escritura ensucia
        // cualquier diff y no aporta nada.
        ksort($byType);

        foreach ($byType as $type => $people) {
            $lines[] = 'absences_current{type="'.TextfileExposition::escapeLabel($type).'"} '.$people;
        }

        TextfileExposition::write(self::FILE, $lines);
    }
}
