<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\SiteNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Site;

/**
 * Los centros de trabajo.
 *
 * `add()` devuelve el centro **con su identificador ya asignado** en lugar de
 * `void`: la clave la pone la base de datos y el caso de uso la necesita para
 * responder.
 */
interface SiteRepository
{
    /**
     * @throws SiteNameAlreadyTaken
     */
    public function add(Site $site): Site;

    /**
     * @throws SiteNameAlreadyTaken
     */
    public function save(Site $site): void;

    public function findById(int $id): ?Site;

    /**
     * @return list<Site>
     */
    public function all(): array;
}
