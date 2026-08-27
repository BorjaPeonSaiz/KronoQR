<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Site as SiteEntity;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Los centros sobre Eloquent.
 *
 * `settings` y `compliance_profile_id` no se tocan aqui aunque esten en la
 * tabla: son de `Product`. Este repositorio escribe exactamente los dos campos
 * que este modulo gobierna.
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

    public function findById(int $id): ?SiteEntity
    {
        $row = Site::query()->find($id);

        return $row instanceof Site ? $this->toEntity($row) : null;
    }

    public function all(): array
    {
        return array_values(array_map($this->toEntity(...), Site::query()->orderBy('name')->get()->all()));
    }

    private function toEntity(Site $row): SiteEntity
    {
        return new SiteEntity(
            id: $row->id,
            name: $row->name,
            timezone: SiteTimezone::fromString($row->timezone),
        );
    }

    private function translate(QueryException $exception, string $name): QueryException|SiteNameAlreadyTaken
    {
        return str_contains($exception->getMessage(), 'sites_name_unique')
            ? SiteNameAlreadyTaken::forName($name)
            : $exception;
    }
}
