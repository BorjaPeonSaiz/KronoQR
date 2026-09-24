<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\AbsenceMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;
use DateTimeZone;

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
 * **Con la hermana de «que dia se midio», resto del cierre de la Fase 3.**
 * `absences_metrics_day_seconds` es la fecha civil medida (medianoche UTC),
 * mismo papel y mismo tipo de valor que `compliance_metrics_week_start_seconds`
 * (y misma tecnica que `adoption_metrics_work_date_seconds`, que la calcula
 * igual desde una fecha `AAAA-MM-DD`): sin ella, un `reporting:absence-metrics`
 * que dejo de correr se lee en el cuadro exactamente igual que un mes sin
 * ausencias. **Una sola serie de frescura y no dos** —al contrario que
 * adopcion, que ademas publica cuando corrio—: aqui basta con saber que dia
 * describen las cuatro etiquetas, que es la misma pregunta que resuelve la de
 * cumplimiento, y por eso seguimos el precedente de una sola serie.
 * **Sigue sin alerta** (§8.4): es un indicador de RRHH, no hay runbook que
 * sostenga despertar a nadie por el.
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

        $lines[] = '# HELP absences_metrics_day_seconds Fecha civil medida por absences_current, a medianoche UTC (RF-GP-04). Delata una tarea programada que dejo de ejecutarse: sin ella, un mes sin recalculo se lee igual que un mes sin ausencias.';
        $lines[] = '# TYPE absences_metrics_day_seconds gauge';
        $lines[] = 'absences_metrics_day_seconds '.$this->midnightOf($onDate);

        TextfileExposition::write(self::FILE, $lines);
    }

    /**
     * La fecha civil medida, como instante, para que sea un numero y no una
     * etiqueta (mismo motivo y misma tecnica que
     * `TextfileAdoptionMetrics::midnightOf()`: como etiqueta seria una serie
     * nueva cada dia).
     */
    private function midnightOf(string $onDate): int
    {
        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $onDate, new DateTimeZone('UTC'));

        return $midnight === false ? 0 : $midnight->getTimestamp();
    }
}
