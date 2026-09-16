<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\ComplianceMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Publica las dos metricas de cumplimiento para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2, decision 10 de la ficha 3.4).
 *
 * ```
 * compliance_findings_last_week{rule="insufficient_rest"}
 * compliance_employees_affected_last_week
 * compliance_metrics_week_start_seconds
 * ```
 *
 * **Por fichero y no por Redis**, como sus hermanas de presencia y adopcion: quien
 * produce estos numeros es un comando programado que corre y termina, y un
 * contador en memoria de un proceso que termina no lo lee nadie.
 *
 * **Se escriben las cuatro reglas, tambien las que estan a cero.** Una serie que
 * desaparece es indistinguible de una que nunca tuvo nada, y el cero es justo lo
 * que se mira: «ningun descanso corto la semana pasada». La suspendida (RN-12)
 * sale a cero por la misma razon: su ausencia haria pensar que la regla no existe.
 *
 * **`compliance_metrics_week_start_seconds` dice QUE semana se midio.** Sin ella,
 * un cuadro de mando con las cifras congeladas —porque el comando dejo de
 * ejecutarse— se lee exactamente igual que una semana tranquila. Es el mismo papel
 * que `presence_metrics_timestamp_seconds`, con una diferencia: aqui lo que
 * importa no es cuando se calculo sino **de que semana habla**, porque el gauge se
 * reescribe identico los siete dias siguientes.
 *
 * La mecanica de escritura —guard del colector, escritura atomica, fallo ruidoso y
 * escapado— es de {@see TextfileExposition}, la misma para los adaptadores del
 * producto. Aqui solo se componen las lineas.
 */
final readonly class TextfileComplianceMetrics implements ComplianceMetrics
{
    private const string FILE = 'kronoqr_compliance.prom';

    public function publish(
        array $findingsByRule,
        int $employeesAffected,
        string $weekStartsOn,
        DateTimeImmutable $at,
    ): void {
        $lines = [
            '# HELP compliance_findings_last_week Alertas de cumplimiento de la ultima semana completa del perfil, por regla (RF-PA-06). Es el mismo recuento que enseña la vista de cumplimiento del panel.',
            '# TYPE compliance_findings_last_week gauge',
        ];

        // Orden estable: un fichero que cambia de orden en cada escritura ensucia
        // cualquier diff y no aporta nada.
        ksort($findingsByRule);

        foreach ($findingsByRule as $rule => $count) {
            $lines[] = 'compliance_findings_last_week{rule="'.TextfileExposition::escapeLabel($rule).'"} '.$count;
        }

        $lines[] = '# HELP compliance_employees_affected_last_week Personas con al menos una alerta de cumplimiento en la ultima semana completa. No es la suma de la serie de arriba: una misma persona puede acumular varias.';
        $lines[] = '# TYPE compliance_employees_affected_last_week gauge';
        $lines[] = 'compliance_employees_affected_last_week '.$employeesAffected;

        $lines[] = '# HELP compliance_metrics_week_start_seconds Primer dia de la semana medida, en segundos. Delata que la tarea programada dejo de ejecutarse: sin ella, unas cifras congeladas se leen igual que una semana tranquila.';
        $lines[] = '# TYPE compliance_metrics_week_start_seconds gauge';
        $lines[] = 'compliance_metrics_week_start_seconds '.$this->epochOf($weekStartsOn);

        TextfileExposition::write(self::FILE, $lines);
    }

    /**
     * La fecha civil del inicio de semana, en segundos desde la epoca.
     *
     * Se fija a medianoche UTC por lo mismo que en el resto del modulo: es una
     * etiqueta de calendario que solo se compara consigo misma, y Prometheus solo
     * admite numeros.
     */
    private function epochOf(string $weekStartsOn): int
    {
        return (new DateTimeImmutable($weekStartsOn.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    }
}
