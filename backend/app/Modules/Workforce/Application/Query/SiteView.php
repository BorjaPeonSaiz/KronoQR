<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneLock;

/**
 * El centro tal como lo publica la API: el centro y si su zona horaria ya esta
 * fija (`Site.timezone_locked`, RN-23, ADR-056).
 *
 * El bloqueo no es un atributo de la fila `sites` sino un hecho del registro
 * horario, y por eso viaja al lado del modelo y no dentro.
 */
final readonly class SiteView
{
    public function __construct(
        public Site $site,
        public SiteTimezoneLock $timezoneLock,
    ) {}

    /**
     * Recien creado en la puesta en marcha: todavia no puede haber ningun
     * tramo, porque un tramo cuelga del centro.
     */
    public static function justCreated(Site $site): self
    {
        return new self($site, SiteTimezoneLock::Open);
    }

    public function timezoneLocked(): bool
    {
        return $this->timezoneLock->isLocked();
    }
}
