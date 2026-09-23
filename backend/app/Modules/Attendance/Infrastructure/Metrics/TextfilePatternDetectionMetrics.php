<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Metrics;

use App\Modules\Attendance\Application\Port\PatternDetectionMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

/**
 * Publica el desenlace de la deteccion de patrones anomalos para el colector
 * *textfile* de `node-exporter` (doc 02 §8.2, RF-PR-06, tarea 3.11).
 *
 * ```
 * pattern_detection_last_run_timestamp_seconds 1774240500
 * pattern_detection_last_failures 0
 * ```
 *
 * ## Por que *textfile* y no Redis
 *
 * El mismo argumento que sostiene a {@see TextfileIncidentDetectionMetrics}, del
 * que esto es una copia deliberada: «¿corrio anoche?» y «¿dejo algo sin hacer?»
 * se leen igual de mal cuando la serie desaparece. Un `FLUSHALL` en un
 * despliegue devolveria «ninguna pasada» y «cero fallos», que es exactamente
 * como se lee una instalacion tranquila. Los hallazgos por patron si van a Redis
 * (`anomalous_patterns_detected_total{pattern}`): aquella es una serie de
 * tendencia y un escalon en la curva no cuesta ninguna decision.
 *
 * ## Dos series, sin etiquetas
 *
 * Las dos que el §8.2 declara y que sostienen las alertas
 * `DeteccionDePatronesAusente` (sin pasada en 26 h) y
 * `DeteccionDePatronesConFallos` (`> 0`), en
 * `infra/observability/prometheus/rules/incidents.yml`. Sin etiquetas
 * a proposito: la instalacion es de un solo centro (ADR-016), y una etiqueta por
 * patron duplicaria `anomalous_patterns_detected_total{pattern}`. Aqui la
 * pregunta es de la **pasada**, no del hallazgo.
 *
 * **Lo que NO alerta es el hallazgo** (decision 9 de la ficha): un indicio sobre
 * dos personas concretas se revisa en la bandeja por quien conoce el turno, no
 * se enruta a un canal de guardia. Lo que alerta es que la pasada no corra o
 * falle, que es operacion y no personas.
 *
 * Son `gauge` y no `counter` porque describen la ultima ejecucion:
 * `pattern_detection_last_failures > 0` se lee «la de anoche dejo algo sin
 * abrir» y vuelve a cero sola en cuanto una pasada sale limpia.
 *
 * **La mecanica de escritura no vive aqui.** El guard del colector, la escritura
 * atomica y el fallo ruidoso son de {@see TextfileExposition}.
 */
final readonly class TextfilePatternDetectionMetrics implements PatternDetectionMetrics
{
    private const string FILE = 'kronoqr_pattern_detection.prom';

    public function scanCompleted(int $failures, DateTimeImmutable $at): void
    {
        TextfileExposition::write(self::FILE, [
            '# HELP pattern_detection_last_run_timestamp_seconds Momento de la ultima deteccion de patrones anomalos de uso de credencial (RF-PR-06). Su ausencia delata que la tarea programada dejo de ejecutarse y que el prestamo de tarjeta deja de vigilarse.',
            '# TYPE pattern_detection_last_run_timestamp_seconds gauge',
            'pattern_detection_last_run_timestamp_seconds '.$at->getTimestamp(),
            '# HELP pattern_detection_last_failures Hallazgos que la ultima deteccion de patrones NO pudo convertir en incidencia. Distinto de cero significa que hay indicios que nadie ve en la bandeja.',
            '# TYPE pattern_detection_last_failures gauge',
            'pattern_detection_last_failures '.$failures,
        ]);
    }
}
