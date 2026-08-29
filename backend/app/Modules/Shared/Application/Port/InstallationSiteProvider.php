<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\InstallationSite;

/**
 * El centro de trabajo de la instalacion, para quien no es `Workforce`.
 *
 * **Hay exactamente uno** (ADR-040). Lo necesita `Identity` para etiquetar las
 * metricas de cobertura de credenciales con el centro aunque no haya ni una
 * fila que contar, y lo necesitara el asistente de puesta en marcha para saber
 * si la instalacion ya esta inicializada. Ninguno de los dos puede importar
 * `Workforce` (doc 02 §1.6), asi que el puerto vive aqui y el adaptador en
 * `Workforce/Infrastructure/Adapter/` (ADR-025, restriccion 3), igual que
 * {@see EmployeeCardDirectory}.
 *
 * `null` solo antes de la puesta en marcha (RF-PD-03): quien lo reciba decide
 * si puede seguir sin centro —casi nunca— o lanza
 * `Shared\Domain\Exception\InstallationSiteMissing`.
 */
interface InstallationSiteProvider
{
    public function installationSite(): ?InstallationSite;
}
