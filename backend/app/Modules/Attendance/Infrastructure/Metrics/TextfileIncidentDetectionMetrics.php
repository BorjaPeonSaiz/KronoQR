<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Metrics;

use App\Modules\Attendance\Application\Port\IncidentDetectionMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

/**
 * Publica el desenlace de la revision diaria para el colector *textfile* de
 * `node-exporter` (doc 02 §8.2, RF-PR-01, tarea 3.2).
 *
 * ```
 * incident_detection_last_run_timestamp_seconds 1773545400
 * incident_detection_work_days_inspected 42
 * incident_detection_last_findings 3
 * incident_detection_last_failures 0
 * ```
 *
 * ## Por que *textfile* y no Redis, teniendo `RedisAnomalyMetrics` al lado
 *
 * `RedisAnomalyMetrics` cuenta hallazgos por tipo y es una serie de tendencia:
 * si un despliegue vacia Redis, la curva tiene un escalon y no se pierde
 * ninguna decision. Aqui se publica lo contrario —«¿corrio anoche?» y «¿dejo
 * algo sin hacer?»— y las dos respuestas se leen igual de mal cuando la serie
 * desaparece: sin sello de tiempo, un planificador parado es indistinguible de
 * una noche tranquila, y sin `..._last_failures`, un fallo que se repite cada
 * noche se ve exactamente igual que una pasada limpia.
 *
 * Es el mismo argumento —y el mismo soporte persistente, `BACKUP_PATH/metrics`—
 * de {@see TextfileProjectionMetrics}.
 *
 * ## Las cuatro series se escriben juntas y sin etiquetas
 *
 * Sin etiquetas a proposito: la instalacion es de un solo centro (ADR-016) y una
 * etiqueta por tipo de hallazgo duplicaria lo que ya publica
 * `anomalous_patterns_detected_total{pattern}`. Aqui la pregunta es de la
 * **pasada**, no del hallazgo.
 *
 * `incident_detection_work_days_inspected` acompaña a las otras tres por lo
 * mismo que su gemela de la proyeccion: sin ella, «cero hallazgos» no distingue
 * una noche tranquila de una pasada que no miro nada.
 *
 * Son `gauge` y no `counter` porque describen la ultima ejecucion, no un
 * acumulado: `incident_detection_last_failures > 0` se lee «la de anoche dejo
 * algo sin abrir» y vuelve a cero sola en cuanto una pasada sale limpia. Las
 * vigilan `DeteccionDeIncidenciasConFallos` y `DeteccionDeIncidenciasAusente`
 * (`infra/observability/prometheus/rules/incidents.yml`).
 *
 * **La mecanica de escritura no vive aqui.** El guard del colector, la escritura
 * atomica y el fallo ruidoso son de {@see TextfileExposition}, que es la misma
 * para los nueve adaptadores del producto. Aqui solo se componen las lineas.
 */
final readonly class TextfileIncidentDetectionMetrics implements IncidentDetectionMetrics
{
    private const string FILE = 'kronoqr_incident_detection.prom';

    public function scanCompleted(
        int $workDaysInspected,
        int $findings,
        int $failures,
        DateTimeImmutable $at,
    ): void {
        TextfileExposition::write(self::FILE, [
            '# HELP incident_detection_last_run_timestamp_seconds Momento de la ultima revision diaria del registro horario. Su ausencia delata que la tarea programada dejo de ejecutarse y que nadie vigila los turnos abiertos.',
            '# TYPE incident_detection_last_run_timestamp_seconds gauge',
            'incident_detection_last_run_timestamp_seconds '.$at->getTimestamp(),
            '# HELP incident_detection_work_days_inspected Jornadas revisadas en la ultima pasada.',
            '# TYPE incident_detection_work_days_inspected gauge',
            'incident_detection_work_days_inspected '.$workDaysInspected,
            '# HELP incident_detection_last_findings Hallazgos emitidos en la ultima pasada, esten o no abiertos como incidencia.',
            '# TYPE incident_detection_last_findings gauge',
            'incident_detection_last_findings '.$findings,
            '# HELP incident_detection_last_failures Hallazgos que la ultima pasada NO pudo convertir en incidencia. Distinto de cero significa que hay trabajo que nadie ve en la bandeja.',
            '# TYPE incident_detection_last_failures gauge',
            'incident_detection_last_failures '.$failures,
        ]);
    }
}
