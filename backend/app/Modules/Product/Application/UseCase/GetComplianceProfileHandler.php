<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;

/**
 * El perfil de cumplimiento vigente para el centro de la instalacion
 * (**RF-PD-07**).
 *
 * ## Sin parametro de centro
 *
 * ADR-040: una licencia es un hotel y hay exactamente un centro. Pedirlo por
 * parametro obligaria al panel a elegir entre una sola opcion y abriria la
 * puerta a construir multi-centro por detras, que es justo lo que ese ADR
 * cerro.
 *
 * ## `null` no es un error de programacion, es un estado de la instalacion
 *
 * Devuelve `null` **antes de la puesta en marcha** (RF-PD-03), cuando todavia no
 * hay centro, y tambien si no hay ningun perfil en la base de datos. Los dos
 * casos se responden con `404` en el borde y los dos se arreglan una sola vez.
 * Lo que no hace es inventar un perfil: sin fila no hay umbral, y un umbral legal
 * escrito en PHP es lo que la regla dura 14 prohibe.
 */
final readonly class GetComplianceProfileHandler
{
    public function __construct(
        private ComplianceProfileRepository $profiles,
        private InstallationSiteProvider $sites,
    ) {}

    public function handle(): ?ComplianceProfileSnapshot
    {
        $site = $this->sites->installationSite();

        if ($site === null) {
            return null;
        }

        return $this->profiles->forSite($site->id);
    }
}
