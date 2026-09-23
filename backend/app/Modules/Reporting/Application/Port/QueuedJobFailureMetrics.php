<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Cuenta el fallo de un trabajo en cola que **no propaga su excepcion**
 * (`queue_jobs_failed_total{job}`, doc 02 §8.2).
 *
 * ## Por que hace falta contarlo a mano
 *
 * Laravel emite `JobFailed` —y `QueueJobMetrics` incrementa la serie— cuando el
 * trabajo **deja salir** la excepcion. `GenerateReportExportJob` la captura a
 * proposito: con `QUEUE_CONNECTION=sync`, que es una configuracion legitima de
 * una instalacion pequeña, dejarla salir convertiria el `202` de una peticion
 * que hizo exactamente lo que prometio en un `500`.
 *
 * El precio de esa decision era que **una generacion fallida no movia ninguna
 * metrica**: la fila quedaba en `failed` y en el panel de observabilidad no
 * pasaba nada. Este puerto es el que paga ese precio sin renunciar a la
 * decision.
 *
 * ## Escribe la MISMA serie y la misma etiqueta
 *
 * No una serie nueva: `queue_jobs_failed_total` con `job=<clase corta>`, igual
 * que `QueueJobMetrics`. Dos series para el mismo hecho obligarian a sumarlas en
 * cada consulta y a explicar por que hay dos, y la alerta de cola dejaria de
 * cubrir este trabajo.
 *
 * ## Medir no puede tumbar nada
 *
 * El adaptador traga cualquier fallo de Redis. Se llama desde el `catch` de un
 * trabajo que ya ha ido mal: una excepcion aqui sustituiria la causa original
 * por otra en el log.
 */
interface QueuedJobFailureMetrics
{
    /**
     * @param  string  $job  Clase **corta** del trabajo (`GenerateReportExportJob`). Nunca su
     *                       carga ni su identificador: la cardinalidad de esta serie es el
     *                       numero de trabajos del producto y no puede crecer con el trafico
     *                       (regla dura 21).
     */
    public function failed(string $job): void;
}
