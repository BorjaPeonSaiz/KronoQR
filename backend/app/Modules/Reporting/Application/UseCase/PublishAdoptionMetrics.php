<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\AdoptionMetrics;
use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use DateInterval;
use DateTimeZone;

/**
 * `workdays_complete_ratio{site}` — la metrica de adopcion del §8.2 que alimenta
 * el cuadro de «Impacto y adopcion» (RF-IN-08, tarea 3.1).
 *
 * ## AYER, y no hoy
 *
 * La jornada de ayer es la ultima que ya no va a cambiar por si sola. Medir el
 * dia en curso daria un ratio que empieza en cero a las 06:00 y sube durante
 * toda la jornada: no describiria la adopcion sino la hora a la que se mira. Es
 * el mismo criterio que `attendance:reconcile`.
 *
 * ## En la zona del centro, no en UTC
 *
 * `work_date` es una fecha civil y un turno de 22:00 a 06:00 pertenece a la
 * jornada de su hora de inicio (RN-05, regla dura 4). Calcular «ayer» en UTC
 * partiria el dia por donde no toca en cuanto el centro este en `Europe/Madrid`
 * y la tarea corra de madrugada — exactamente cuando corre.
 *
 * ## Sin centro no hace nada, y no falla
 *
 * Instalacion recien instalada, antes del asistente de puesta en marcha
 * (RF-PD-03). Mismo desenlace que `PublishPresenceMetrics`: devuelve `false` y
 * el comando sale en verde. Una tarea programada que fallara cada dia en una
 * instalacion nueva entrena a no mirar el planificador.
 */
final readonly class PublishAdoptionMetrics
{
    public function __construct(
        private WorkDayCompletionReader $workDays,
        private AdoptionMetrics $metrics,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    public function handle(): bool
    {
        $site = $this->installation->installationSite();

        if ($site === null) {
            return false;
        }

        $now = $this->clock->now();
        $yesterday = $now
            ->setTimezone(new DateTimeZone($site->timezone))
            ->sub(new DateInterval('P1D'))
            ->format('Y-m-d');

        $this->metrics->publish(
            bySite: $this->workDays->completionOn($yesterday),
            workDate: $yesterday,
            at: $now,
        );

        return true;
    }
}
