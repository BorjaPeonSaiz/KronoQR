<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * El rastro de que la deteccion de patrones anomalos se ejecuta, y de si dejo
 * algo sin hacer (doc 02 §8.2, RF-PR-06, tarea 3.11).
 *
 * ## Hermana de `IncidentDetectionMetrics`, y por el mismo motivo
 *
 * {@see AnomalyMetrics} publica `anomalous_patterns_detected_total{pattern}`, un
 * contador de tendencia que vive en Redis. Lo que este puerto publica es el
 * **desenlace de la pasada**: cuando corrio y cuantos hallazgos no se pudieron
 * convertir en incidencia. Eso tiene que sobrevivir a un `FLUSHALL` de
 * despliegue, porque «ninguna pasada» y «cero fallos» se leen exactamente igual
 * que una instalacion tranquila. De ahi el adaptador *textfile*.
 *
 * ## Se publica SIEMPRE, tambien sin centro y sin hallazgos
 *
 * Antes de la puesta en marcha no hay centro (RF-PD-03) y la pasada no revisa
 * nada; una noche normal no encuentra nada. En los dos casos se publica, porque
 * el silencio de la serie es justo lo que `DeteccionDePatronesAusente` tiene que
 * poder distinguir de un planificador parado.
 *
 * ## Dos series y no cuatro
 *
 * El §8.2 declara `pattern_detection_last_run_timestamp_seconds` y
 * `pattern_detection_last_failures`, que son las dos preguntas que sostienen una
 * alerta: «¿corrio anoche?» y «¿dejo algo sin abrir?». Cuantos escaneos se
 * miraron y cuantos hallazgos salieron no sostienen ninguna —el volumen de
 * fichajes ya se ve en `scans_total`, y los hallazgos en
 * `anomalous_patterns_detected_total{pattern}`—, asi que no se publican: una
 * serie que nadie consulta es una serie que nadie mantiene.
 *
 * ## Son recuentos, nunca personas (regla dura 21)
 *
 * Ni una etiqueta. Un indicio de posible prestamo de credencial es informacion
 * sensible sobre dos personas concretas, y una serie temporal por persona seria
 * el fichero que este producto no puede tener.
 */
interface PatternDetectionMetrics
{
    /**
     * @param  int  $failures  hallazgos que NO se pudieron convertir en incidencia
     */
    public function scanCompleted(int $failures, DateTimeImmutable $at): void;
}
