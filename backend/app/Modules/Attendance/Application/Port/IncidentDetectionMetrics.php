<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeImmutable;

/**
 * El rastro de que la revision diaria del registro horario se ejecuta, y de si
 * dejo algo sin hacer (doc 02 §8.2, RF-PR-01, tarea 3.2).
 *
 * ## Por que hace falta un puerto mas, teniendo ya `AnomalyMetrics`
 *
 * Cuentan cosas distintas y sobreviven a cosas distintas.
 * {@see AnomalyMetrics} publica `anomalous_patterns_detected_total{pattern}`:
 * un contador de **hallazgos por tipo** que vive en Redis porque es una serie de
 * tendencia y su valor exacto no sostiene ninguna decision de madrugada.
 *
 * Lo que este puerto publica es el **desenlace de la pasada**: cuando corrio,
 * cuantas jornadas miro y cuantos hallazgos no se pudieron convertir en
 * incidencia. Eso tiene que sobrevivir a un reinicio de Redis por el mismo
 * argumento que {@see ProjectionMetrics}: un `FLUSHALL` en un despliegue
 * devolveria «cero fallos» y «ninguna pasada», que es exactamente como se lee
 * una instalacion tranquila. De ahi el adaptador *textfile*.
 *
 * ## Se publica SIEMPRE, tambien sin centro
 *
 * Antes de la puesta en marcha no hay centro (RF-PD-03) y la pasada no revisa
 * nada. Aun asi se publica, con ceros: una serie que solo aparece cuando hay
 * centro es indistinguible de un planificador parado, y la alerta
 * `DeteccionDeIncidenciasAusente` —que es la que descubre que nadie esta
 * mirando los turnos abiertos— no podria separar los dos casos.
 *
 * ## Son recuentos, nunca personas
 *
 * Ni una etiqueta con `employee_uuid`, con departamento ni con tipo de hallazgo:
 * eso ya lo cuenta `AnomalyMetrics`, y aqui una etiqueta por persona seria un
 * registro paralelo de a quien se le abren incidencias (regla dura 21).
 */
interface IncidentDetectionMetrics
{
    /**
     * @param  int  $workDaysInspected  jornadas revisadas en la pasada
     * @param  int  $findings  hallazgos emitidos, esten o no abiertos
     * @param  int  $failures  hallazgos que NO se pudieron convertir en incidencia
     */
    public function scanCompleted(
        int $workDaysInspected,
        int $findings,
        int $failures,
        DateTimeImmutable $at,
    ): void;
}
