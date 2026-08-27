<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Domain\Model\Site;

/**
 * Lecturas de centros. Sin paginacion: un cliente tiene hoteles, no miles de
 * centros, y el techo real lo pone su licencia (`max_sites`).
 */
final readonly class SiteQueries
{
    public function __construct(private SiteRepository $sites) {}

    /**
     * @return list<Site>
     */
    public function all(): array
    {
        return $this->sites->all();
    }

    public function find(int $id): ?Site
    {
        return $this->sites->findById($id);
    }
}
