<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * Las dos metricas de negocio del fichaje (doc 02 §8.2):
 * `scans_total{device,result}` y `scan_processing_duration_seconds`.
 *
 * Es un puerto y no una llamada directa por el mismo motivo que
 * `Compliance\Application\Port\AuditMetrics`: quien mide no sabe si detras hay
 * un contador en Redis, un fichero para el colector *textfile* o nada. El
 * endpoint `/metrics` que las publica es de la tarea 3.1; en las pruebas es un
 * doble que cuenta y permite afirmar sobre lo medido sin levantar Prometheus.
 *
 * **`result` es el desenlace detallado y aqui si puede serlo.** El §8.2 exige
 * que `scans_total` distinga `rejected_signature` de `rejected_revoked` y de
 * `rejected_unknown` —es lo que pide el escenario *QR falsificado* del doc 01
 * §11—, mientras que la respuesta HTTP no puede distinguirlos (RS-03, regla
 * dura 17). No hay contradiccion: `/metrics` esta restringido a la red interna
 * y quien lo lee es quien opera el sistema, no quien presenta una tarjeta.
 *
 * **`device` es el UUID publico del quiosco**, nunca su clave interna, y la
 * cardinalidad es la de los quioscos del hotel: unidades, no miles. Ningun
 * `employee_uuid` aparece como etiqueta: una serie temporal por persona seria
 * un registro de presencia paralelo, sin retencion ni control de acceso
 * (RGPD, minimizacion).
 */
interface ScanMetrics
{
    /**
     * Un escaneo procesado, con lo que tardo en procesarse.
     *
     * Las dos metricas se publican en la misma llamada porque describen el
     * mismo hecho: separarlas permitiria contar un escaneo sin su duracion, y
     * entonces el percentil 95 de RNF-P-01 se calcularia sobre una muestra
     * distinta del contador.
     *
     * @param  string  $deviceUuid  Identificador publico del quiosco.
     * @param  float  $durationSeconds  Tiempo de proceso en el servidor.
     */
    public function scanProcessed(string $deviceUuid, ScanResult $result, float $durationSeconds): void;

    /**
     * Un lote de la cola offline sincronizado: `sync_delay_seconds{device}` y su
     * tamano (§8.2, tarea 1.7).
     *
     * **`delaySeconds` no mide lo que tardo el servidor**, sino cuanto tiempo
     * estuvo el fichaje mas antiguo del lote esperando en la tablet. Es la unica
     * senal que dice si una cola esta drenando o lleva media jornada atascada, y
     * es la que en la Fase 2 abre la incidencia de RN-15.
     *
     * Va en este puerto y no en uno de `Kiosk` porque lo que se mide son
     * escaneos: el modulo que los procesa es el que sabe cuantos eran y con
     * cuanto retraso llegaron. `kiosk_last_seen_seconds` y
     * `kiosk_offline_queue_size` si son de `Kiosk`, porque los declara el
     * dispositivo en su latido y no tienen nada que ver con el fichaje.
     *
     * @param  string  $deviceUuid  Identificador publico del quiosco.
     * @param  int  $size  Escaneos que traia el lote.
     * @param  int  $delaySeconds  Segundos entre el `occurred_at` mas antiguo del lote y
     *                             su recepcion. Nunca negativo.
     */
    public function batchSynchronised(string $deviceUuid, int $size, int $delaySeconds): void;

    /**
     * `pin_fallback_scans_total{site}` (§8.2, RF-AT-11, tarea 1.12).
     *
     * **Es un termometro, no una alarma.** El §8.2 lo dice: *«una subida indica
     * un problema con la emision, el estado de las tarjetas o la disciplina de
     * la plantilla»*. Nadie ficha con el PIN porque le apetezca —hay que
     * recordar un codigo y seis digitos, frente a acercar una tarjeta—, asi que
     * una decena de fichajes por PIN en un centro en un dia significa que las
     * tarjetas se estan cayendo a pedazos o que la ultima remesa no se entrego.
     * Cuesta un contador y evita descubrirlo por la reclamacion de una nomina.
     *
     * **La etiqueta es el centro y no el dispositivo**, al reves que
     * `scans_total`. La pregunta que se hace de verdad es «¿en que hotel estan
     * fallando las tarjetas?», y no «¿en que tablet?»: el empleado usa la que
     * tiene delante. Y desde luego no lleva `employee_uuid`: una serie temporal
     * de quien ficha sin tarjeta seria una lista de sospechosos con retencion
     * indefinida (regla dura 21, RGPD).
     *
     * @param  int  $siteId  Centro del empleado que ficho.
     */
    public function pinFallbackScan(int $siteId): void;
}
