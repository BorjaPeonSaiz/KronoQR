<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use Throwable;

/**
 * `GET /api/v1/diagnostics/errors` — el historico agrupado, con sus filtros
 * (RF-PD-15).
 *
 * ## Delgado, y con motivo
 *
 * No hay ninguna decision que tomar aqui: los filtros ya llegan tipados en
 * {@see ErrorEventQuery}, el orden y los recuentos los resuelve la misma consulta
 * que trae las filas, y la autorizacion la hizo la policy. Lo unico que anade es
 * lo que la pantalla necesita para pintar y no puede deducir: la zona del centro
 * y el reloj del servidor.
 *
 * ## Sin centro, UTC, y no una excepcion
 *
 * Aqui esta la diferencia con la bandeja de incidencias, que **lanza** cuando no
 * hay centro. Aquella describe jornadas y sin zona no puede decir nada cierto;
 * esta describe fallos tecnicos, y el momento en el que una instalacion todavia
 * no tiene centro —en mitad de la puesta en marcha— es exactamente uno de los
 * momentos en los que hace falta ver que esta fallando. UTC es lo que hay dentro
 * y es la verdad (regla dura 3). Es el mismo criterio de
 * `DatabaseDataExportSource::siteTimezone()`.
 *
 * ## Sin asiento de auditoria, y no es un olvido
 *
 * RS-05 registra el acceso a **datos personales de terceros**, y aqui no hay
 * ninguno: esta tabla no puede contenerlos (regla dura 21). Mirar los errores
 * tecnicos de la propia instalacion no es una divulgacion, y un asiento por
 * consulta llenaria `audit_log` —cuatro anos de retencion y valor probatorio— de
 * ruido tecnico.
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 *
 * Es justamente lo que se necesita cuando algo va mal.
 */
final readonly class ListErrorEvents
{
    public function __construct(
        private ErrorEventRepository $errors,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    public function handle(ErrorEventQuery $query): ErrorHistoryView
    {
        return new ErrorHistoryView(
            page: $this->errors->page($query),
            timeZone: $this->timeZone(),
            generatedAt: $this->clock->now(),
        );
    }

    /**
     * La zona del centro, o UTC.
     *
     * El `catch` cubre el caso en el que la propia consulta del centro falle:
     * este endpoint es de diagnostico y no puede caerse porque otra tabla no
     * responda. Lo que si se cae, y debe, es la consulta del historico: un
     * listado vacio por un fallo silencioso le diria al IT del cliente que no
     * esta pasando nada.
     */
    private function timeZone(): string
    {
        try {
            $site = $this->installation->installationSite();

            return $site instanceof InstallationSite ? $site->timezone : 'UTC';
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
