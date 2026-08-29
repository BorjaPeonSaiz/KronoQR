<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use App\Modules\Workforce\Infrastructure\Persistence\Site;

/**
 * El centro de la instalacion, leido de `sites` (ADR-040).
 *
 * Tercera arista de ADR-025 sobre la misma tabla que {@see EloquentSiteCalendar}:
 * el puerto lo declara `Shared` porque lo consume `Identity`, y lo implementa
 * este modulo porque la tabla es suya.
 *
 * `orderBy('id')` y no `first()` a secas: el indice `sites_single_row_uidx`
 * garantiza que haya como mucho una fila, pero el orden explicito hace que la
 * lectura sea deterministica tambien en una base de datos a la que alguien le
 * haya quitado el indice a mano.
 */
final readonly class EloquentInstallationSiteProvider implements InstallationSiteProvider
{
    public function installationSite(): ?InstallationSite
    {
        $row = Site::query()->select(['id', 'name', 'timezone'])->orderBy('id')->first();

        if (! $row instanceof Site) {
            return null;
        }

        return new InstallationSite(id: $row->id, name: $row->name, timezone: $row->timezone);
    }
}
