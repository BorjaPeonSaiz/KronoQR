<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\DeployedVersionProvider;

/**
 * La version del servidor que se le diga, sin depender del fichero `VERSION`
 * del repositorio, que cambia con cada publicacion.
 */
final readonly class FixedDeployedVersion implements DeployedVersionProvider
{
    public function __construct(private string $version) {}

    #[\Override]
    public function deployedVersion(): string
    {
        return $this->version;
    }
}
