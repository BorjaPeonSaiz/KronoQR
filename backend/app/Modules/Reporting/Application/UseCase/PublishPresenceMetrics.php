<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCase;

use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Application\Port\PresenceMetrics;
use App\Modules\Reporting\Application\Port\RealtimeConnectionCounter;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;

/**
 * Recalcula y publica las dos metricas de presencia del doc 02 §8.2.
 *
 * ```
 * open_shifts_current{site,site_name,department}   gauge
 * websocket_connections_active                     gauge
 * ```
 *
 * ## Se recalcula entero, nunca se incrementa
 *
 * Es la regla dura 7 aplicada a la instrumentacion, y la razon es la misma que
 * en `daily_totals`: un contador que subiera con cada entrada y bajara con cada
 * salida se desviaria en el primer mensaje perdido —una excepcion en un listener,
 * un trabajo que muere— y **nadie lo notaria**, porque un gauge equivocado no
 * duele hasta que alguien decide algo con el. Un recuento completo es correcto
 * por construccion.
 *
 * Y ademas recoge lo que no pasa por un fichaje: una anulacion, una correccion
 * que cierra un turno olvidado, una carga inicial. Difundir la metrica desde el
 * listener de presencia habria dejado fuera esos tres casos.
 *
 * ## Cada minuto, y no en cada peticion
 *
 * Lo ejecuta el planificador. El endpoint del panel **no** publica metricas, por
 * lo mismo que `credentials:status`: no se toca el disco en cada peticion, y
 * menos en una pantalla que se sondea cada 15 s. Un minuto es ademas la cadencia
 * a la que `node-exporter` sirve el fichero: refrescarlo mas a menudo solo
 * engorda la base de metricas.
 *
 * ## Sin centro no hay metrica
 *
 * Antes de la puesta en marcha (RF-PD-03) no hay centro con el que etiquetar, y
 * publicar `site=""` seria inventar una serie que despues habria que reconciliar.
 * Se devuelve `false` y quien llama lo dice por su salida.
 */
final readonly class PublishPresenceMetrics
{
    public function __construct(
        private LivePresenceReader $presence,
        private RealtimeConnectionCounter $connections,
        private PresenceMetrics $metrics,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    /**
     * @return bool `false` si todavia no hay centro de trabajo que etiquetar.
     */
    public function handle(): bool
    {
        $site = $this->installation->installationSite();

        if ($site === null) {
            return false;
        }

        $this->metrics->publish(
            siteId: $site->id,
            siteName: $site->name,
            openShiftsByDepartment: $this->presence->openShiftsByDepartment(),
            // `null` cuando Reverb no contesta, y el publicador omite la serie:
            // «no se sabe» y «no hay nadie mirando» son dos hechos distintos
            // (ADR-011).
            websocketConnections: $this->connections->activeConnections(),
            at: $this->clock->now(),
        );

        return true;
    }
}
