<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

/**
 * Las metricas de salud del quiosco del doc 02 §8.2:
 * `kiosk_last_seen_seconds{device}` y `kiosk_offline_queue_size{device}`.
 *
 * Es un puerto y no una llamada directa por el mismo motivo que
 * `Attendance\Application\Port\ScanMetrics`: quien mide no sabe si detras hay un
 * contador en Redis, un fichero para el colector *textfile* o nada. El endpoint
 * `/metrics` que las publica es de la tarea 3.1; en las pruebas es un doble que
 * cuenta.
 *
 * **Son dos gauges, no dos contadores**, y esa es la diferencia que las hace
 * utiles: lo que interesa no es cuantos latidos hubo, sino **cual fue el ultimo**
 * y **cuanto queda en la cola ahora**. Sobre la primera se construye la alerta
 * «quiosco sin latido > 10 min» del doc 01 §9.3 (tarea 3.2), que es lo que hace
 * visible una tablet averiada antes de que alguien reclame una jornada que falta.
 *
 * **`sync_delay_seconds` no esta aqui**: lo emite `Attendance` al sincronizar un
 * lote, porque se mide sobre escaneos reales y no sobre lo que el dispositivo
 * declara (§8.2, `ScanMetrics::batchSynchronised()`).
 *
 * **Ninguna etiqueta lleva `employee_uuid`** ni puede llevarlo: este modulo no ve
 * empleados. `device` es el UUID publico del quiosco y su cardinalidad es la de
 * los quioscos del hotel: unidades.
 */
interface KioskMetrics
{
    /**
     * El latido de un quiosco.
     *
     * Las dos metricas se publican en la misma llamada porque describen el mismo
     * hecho: separarlas permitiria conocer el tamano de la cola sin saber de
     * cuando es el dato, y una cola de 300 elementos de hace ocho horas no
     * significa lo mismo que una de hace un minuto.
     *
     * @param  string  $deviceUuid  Identificador publico del quiosco.
     * @param  int  $seenAtUnixSeconds  Momento del latido, en segundos desde la epoca.
     *                                  Es un instante y no una antiguedad: la resta la
     *                                  hace Prometheus con `time()`, que es lo que
     *                                  mantiene la metrica correcta aunque nadie fiche.
     * @param  int  $pendingQueueSize  Lo que el dispositivo declara tener sin sincronizar.
     */
    public function heartbeat(string $deviceUuid, int $seenAtUnixSeconds, int $pendingQueueSize): void;
}
