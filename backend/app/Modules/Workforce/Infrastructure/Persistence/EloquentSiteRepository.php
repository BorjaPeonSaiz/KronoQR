<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Exception\SiteAlreadyConfigured;
use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
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
 *
 * ## La zona, vigilada tambien por la base de datos (RN-23, ADR-056)
 *
 * Con registro horario, el *trigger* `zz_sites_guard_timezone` rechaza con `42501`
 * cualquier cambio de zona con la credencial del runtime. `save()` lo traduce a
 * {@see SiteTimezoneLocked}: el caso de uso ya lo comprueba antes con la fila
 * bloqueada, asi que solo se ve si alguien llega aqui sin pasar por el. La
 * correccion de consola entra por la funcion `site_correct_timezone`
 * ({@see self::saveTimezoneCorrection()}), la unica puerta que el *trigger* deja
 * abierta (migracion `2026_10_18_120000`).
 */
final readonly class EloquentSiteRepository implements SiteRepository
{
    /** Funcion `SECURITY DEFINER` de la correccion de consola. */
    public const string CORRECTION_FUNCTION = 'site_correct_timezone';

    /** Prefijo con que el *trigger* abre su mensaje: `sites_guard_timezone: <antes> -> <despues>:`. */
    private const string GUARD_MARKER = 'sites_guard_timezone: ';

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
            throw $this->translate($exception, $site->name, $site->timezone->identifier);
        }
    }

    public function saveTimezoneCorrection(SiteEntity $corrected, SiteTimezone $previous): int
    {
        if ($corrected->id === null) {
            throw new RuntimeException('No se puede corregir la zona de un centro que todavia no se ha guardado.');
        }

        // Los errores de la funcion (`P0002` si la zona de partida ya no es
        // esa, `42501` si no hay registro horario) no se traducen: con la fila
        // del centro bloqueada por el caso de uso y el recuento hecho dentro,
        // ninguno puede ocurrir, y si ocurre es un defecto que tiene que verse.
        $row = Site::query()->getConnection()->selectOne(
            'SELECT public.'.self::CORRECTION_FUNCTION.'(?::bigint, ?::text, ?::text) AS change_id',
            [$corrected->id, $previous->identifier, $corrected->timezone->identifier],
        );

        // La misma zona no llega aqui (el caso de uso la descarta antes): sin
        // fila en la traza no hay correccion que asentar.
        $changeId = is_object($row) ? ($row->change_id ?? null) : null;

        if (! is_numeric($changeId)) {
            throw new RuntimeException('site_correct_timezone no ha apuntado ningun cambio en la traza.');
        }

        return (int) $changeId;
    }

    public function installationSite(): ?SiteEntity
    {
        $row = Site::query()->orderBy('id')->first();

        return $row instanceof Site ? $this->toEntity($row) : null;
    }

    public function installationSiteForUpdate(): ?SiteEntity
    {
        $row = Site::query()->orderBy('id')->lockForUpdate()->first();

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

    private function translate(QueryException $exception, string $name, ?string $requestedTimezone = null): QueryException|SiteAlreadyConfigured|SiteNameAlreadyTaken|SiteTimezoneLocked
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'sites_single_row_uidx')) {
            return SiteAlreadyConfigured::make();
        }

        if ($requestedTimezone !== null && $exception->getCode() === '42501' && str_contains($message, self::GUARD_MARKER)) {
            return SiteTimezoneLocked::because($this->lockedTimezone($message) ?? '?', $requestedTimezone);
        }

        return str_contains($message, 'sites_name_unique')
            ? SiteNameAlreadyTaken::forName($name)
            : $exception;
    }

    /**
     * La zona que el *trigger* defiende, del principio de su mensaje. Un nombre
     * IANA no lleva espacios.
     */
    private function lockedTimezone(string $message): ?string
    {
        return preg_match('/'.preg_quote(self::GUARD_MARKER, '/').'(\S+) -> /', $message, $match) === 1 ? $match[1] : null;
    }
}
