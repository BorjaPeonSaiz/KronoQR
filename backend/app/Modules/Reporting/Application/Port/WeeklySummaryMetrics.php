<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use DateTimeImmutable;

/**
 * Publica el desenlace de la pasada del resumen semanal (doc 02 §8.2, decision 8
 * de la ficha 3.12).
 *
 * ```
 * weekly_summary_last_run_timestamp_seconds   gauge
 * weekly_summary_last_sent                    gauge
 * ```
 *
 * **Se publica SIEMPRE**, tambien cuando la pasada no envia nada —apagada, sin
 * SMTP, sin destinatarios o sin la funcionalidad en el plan—: sin eso, «no habia
 * nada que enviar» y «el planificador dejo de correr» se leerian igual. Es el
 * mismo argumento por el que las series de la deteccion de patrones salen por
 * fichero y no por Redis.
 *
 * **Sin alerta, a proposito** (§8.4: una alerta sin runbook es ruido). El
 * resumen es accesorio y opcional; que la pasada falle ya lo recoge
 * `scheduler.command_failed`, y nadie tiene que levantarse a las 06:30 porque un
 * correo de gestion no haya salido.
 */
interface WeeklySummaryMetrics
{
    /**
     * @param  int  $sent  Correos entregados en esta pasada. `0` es un desenlace normal.
     */
    public function passCompleted(int $sent, DateTimeImmutable $at): void;
}
