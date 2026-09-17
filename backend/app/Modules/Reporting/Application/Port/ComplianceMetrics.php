<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use DateTimeImmutable;

/**
 * Las dos metricas de la vista de cumplimiento para el cuadro «Negocio» (doc 02
 * §8.2, paso 7 de la ficha).
 *
 * ```
 * compliance_findings_last_week{rule}          gauge
 * compliance_employees_affected_last_week      gauge
 * ```
 *
 * ## Se recalculan enteras sobre la ULTIMA SEMANA COMPLETA, con la misma consulta
 *
 * Gauges y no contadores: describen «cuantos hallazgos tuvo la semana pasada», no
 * «cuantos van desde que se instalo». Un contador no se podria corregir cuando una
 * correccion de jornada deshace el hallazgo, porque solo puede crecer (regla dura
 * 7 aplicada a la instrumentacion).
 *
 * **Y salen de la MISMA consulta que la pantalla**, con el alcance sin restringir.
 * Es la unica forma de que Grafana y el panel no puedan discrepar: una consulta
 * propia y mas rapida para la metrica seria una segunda definicion de «semana por
 * encima del limite», y el dia que discreparan el cuadro de mando diria una cosa y
 * la pantalla otra delante del mismo cliente.
 *
 * ## Sin alerta, a proposito
 *
 * Es un indicador de RRHH, no un fallo de operacion: nadie tiene que levantarse de
 * noche porque tres personas encadenaran turnos. Una alerta sin runbook es ruido
 * (doc 02 §8.4).
 */
interface ComplianceMetrics
{
    /**
     * @param  array<string, int>  $findingsByRule  las cuatro reglas, tambien las que no encontraron nada
     * @param  int  $employeesAffected  personas con al menos un hallazgo esa semana
     * @param  string  $weekStartsOn  primer dia de la semana medida, `YYYY-MM-DD`
     */
    public function publish(
        array $findingsByRule,
        int $employeesAffected,
        string $weekStartsOn,
        DateTimeImmutable $at,
    ): void;
}
