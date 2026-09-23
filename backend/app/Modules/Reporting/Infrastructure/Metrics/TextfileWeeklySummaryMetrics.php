<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Metrics;

use App\Modules\Reporting\Application\Port\WeeklySummaryMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

/**
 * Publica el desenlace de la pasada del resumen semanal para el colector
 * *textfile* de `node-exporter` (doc 02 §8.2, RF-PR-05, tarea 3.12).
 *
 * ```
 * weekly_summary_last_run_timestamp_seconds 1774240500
 * weekly_summary_last_sent 3
 * ```
 *
 * ## Por que *textfile* y no Redis
 *
 * El mismo argumento que sostiene a {@see TextfileAbsenceMetrics} y a las dos de
 * deteccion: lo que publica un comando que corre y termina no puede vivir en una
 * memoria que un despliegue vacia. Un `FLUSHALL` devolveria «ninguna pasada» y
 * «cero correos», que es exactamente como se lee una instalacion con el resumen
 * apagado.
 *
 * ## Se publica SIEMPRE, tambien cuando no se envia nada
 *
 * Las dos series salen igual con la funcionalidad apagada, sin SMTP, sin plan o
 * sin destinatarios. Sin eso, «no habia nada que enviar» y «el planificador dejo
 * de correr» se leerian igual, que es el unico fallo que esta serie existe para
 * distinguir.
 *
 * ## Sin alerta, a proposito
 *
 * El §8.4 no admite alerta sin runbook que la sostenga, y aqui no lo hay: el
 * resumen es accesorio y opcional (doc 05 §5.7), y nadie tiene que levantarse a
 * las 06:30 porque un correo de gestion no haya salido. Que la pasada **falle**
 * lo recoge `scheduler.command_failed`, que es de operacion y si tiene
 * procedimiento.
 *
 * Son `gauge` y no `counter` porque describen la ultima ejecucion.
 *
 * **La mecanica de escritura no vive aqui**: el guard del colector, la escritura
 * atomica y el fallo ruidoso son de {@see TextfileExposition}.
 */
final readonly class TextfileWeeklySummaryMetrics implements WeeklySummaryMetrics
{
    private const string FILE = 'kronoqr_weekly_summary.prom';

    public function passCompleted(int $sent, DateTimeImmutable $at): void
    {
        TextfileExposition::write(self::FILE, [
            '# HELP weekly_summary_last_run_timestamp_seconds Momento de la ultima pasada del resumen semanal por correo (RF-PR-05). Su ausencia delata que la tarea programada dejo de ejecutarse.',
            '# TYPE weekly_summary_last_run_timestamp_seconds gauge',
            'weekly_summary_last_run_timestamp_seconds '.$at->getTimestamp(),
            '# HELP weekly_summary_last_sent Resumenes entregados en la ultima pasada. Cero es normal: la funcionalidad es opcional y puede estar apagada, sin plan o sin destinatarios.',
            '# TYPE weekly_summary_last_sent gauge',
            'weekly_summary_last_sent '.$sent,
        ]);
    }
}
