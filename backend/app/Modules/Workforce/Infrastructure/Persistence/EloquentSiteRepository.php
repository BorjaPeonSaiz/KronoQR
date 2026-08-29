<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Site as SiteEntity;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * `sites` con una sola fila (ADR-040).
 *
 * La unicidad la impone el indice `sites_single_row_uidx`, no una consulta
 * previa: entre el `SELECT` y el `INSERT` cabria otro alta, y el indice es lo
 * que hace imposible el segundo centro tambien bajo concurrencia. Aqui solo se
 * traduce su violacion a la excepcion del dominio.
 */
final readonly class EloquentSiteRepository implements SiteRepository
{
    public function add(SiteEntity $site): SiteEntity
    {
        try {
            $row = Site::query()->create([
                'name' => $site->name,
                'timezone' => $site->timezone->identifier,
            ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception, $site->name);
        }

        return $site->withId($row->id);
    }

    public function save(SiteEntity $site): void
    {
        if ($site->id === null) {
            throw new RuntimeException('No se puede actualizar un centro que todavia no se ha guardado.');
        }

        try {
            Site::query()->whereKey($site->id)->update([
                'name' => $site->name,
                'timezone' => $site->timezone->identifier,
            ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception, $site->name);
        }
    }

    public function installationSite(): ?SiteEntity
    {
        $row = Site::query()->orderBy('id')->first();

        return $row instanceof Site ? $this->toEntity($row) : null;
    }

    private function toEntity(Site $row): SiteEntity
    {
        return new SiteEntity(
            id: $row->id,
            name: $row->name,
            timezone: SiteTimezone::fromString($row->timezone),
        );
    }

    private function translate(QueryException $exception, string $name): QueryException|SiteAlreadyConfigured|SiteNameAlreadyTaken
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'sites_single_row_uidx')) {
            return SiteAlreadyConfigured::make();
        }

        return str_contains($message, 'sites_name_unique')
            ? SiteNameAlreadyTaken::forName($name)
            : $exception;
    }
}
