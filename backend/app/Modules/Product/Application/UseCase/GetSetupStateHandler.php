<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\SetupFacts;
use App\Modules\Product\Application\Port\SetupProgressRepository;
use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;

/**
 * El estado del asistente de puesta en marcha (**RF-PD-03**).
 *
 * Es el **punto unico** desde el que se decide si el asistente sigue abierto:
 * lo consultan el endpoint publico `GET /api/v1/setup/status`, la marca de un
 * paso y el cierre. Con la comprobacion repartida, un camino podria dar por
 * cerrado lo que otro sigue admitiendo.
 *
 * **Mezcla dos fuentes a proposito**: las marcas guardadas y los hechos que se
 * deducen del dato. Los pasos `administrator` y `site` salen siempre de lo
 * segundo, asi que ni una fila perdida en una restauracion ni una marca escrita
 * a mano pueden hacer que el asistente afirme que hay administrador cuando no lo
 * hay.
 */
final readonly class GetSetupStateHandler
{
    public function __construct(
        private SetupProgressRepository $progress,
        private SetupFacts $facts,
        private InstallationSiteProvider $sites,
    ) {}

    public function handle(): SetupState
    {
        return SetupState::of(
            recorded: $this->progress->recorded(),
            hasAdministrator: $this->facts->hasAdministratorWithSecondFactor(),
            // Por el puerto de `Shared` y no por una consulta propia: es el mismo
            // que ya responde «¿esta la instalacion puesta en marcha?» al resto
            // del producto (ADR-040), y dos formas de preguntarlo acabarian
            // respondiendo distinto.
            hasSite: $this->sites->installationSite() !== null,
            completedAt: $this->progress->completedAt(),
        );
    }
}
