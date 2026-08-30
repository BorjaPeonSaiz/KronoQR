<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Adapter;

use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Compliance\Domain\Policy\RetentionPolicy;
use App\Modules\Compliance\Domain\ValueObject\RetentionPolicySnapshot;
use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use Illuminate\Support\Facades\Config;

/**
 * Junta las dos fuentes de los plazos de retencion (regla dura 14, RL-11).
 *
 * ## De donde sale cada plazo, y por que no del mismo sitio
 *
 * - **Los anos del registro de jornada y de `audit_log`** salen del perfil de
 *   cumplimiento del centro (`compliance_profiles.retention_years`, RF-PD-07).
 *   Los fija la **jurisdiccion**: cambian con el pais y con el convenio, y el
 *   cliente no los elige.
 * - **Los dias del log tecnico y del historico de errores** salen de la
 *   configuracion de la **instalacion** (`ERROR_HISTORY_RETENTION_DAYS`, doc 02
 *   §8.2.1). No son un plazo legal: son cuanto historico tecnico quiere guardar
 *   quien administra el servidor.
 *
 * Mezclar las dos fuentes es trabajo de este adaptador. Si lo hiciera el caso de
 * uso tendria que saber de donde viene cada plazo, y ahi es donde acaban
 * apareciendo los valores por defecto en PHP.
 *
 * ## Un centro, sin parametro
 *
 * ADR-040: una licencia es un hotel. El centro se resuelve con
 * `InstallationSiteProvider` y no se pide a quien llama, que en este caso es una
 * consola sin forma de conocerlo. Sin centro -instalacion recien instalada,
 * RF-PD-03- **falla**: sin perfil no hay plazo, y sin plazo no se purga nada.
 * Fallar es lo correcto; caer en un valor por defecto seria borrar con un plazo
 * inventado.
 *
 * ## Sin cache entre procesos
 *
 * Memoria por ejecucion, con el mismo criterio que el proveedor de perfiles de
 * `Product` (nombrado en prosa y no con `@see`: una referencia de docblock a otro
 * modulo la convierte Pint en un `use` real, y Deptrac la cuenta como
 * dependencia prohibida). El comando pide la politica dos o tres veces y el
 * proceso muere con el, asi que un cambio del perfil surte efecto en la pasada
 * siguiente.
 */
final class ConfiguredRetentionPolicyProvider implements RetentionPolicyProvider
{
    private ?RetentionPolicy $policy = null;

    private ?int $siteId = null;

    public function __construct(
        private readonly CompliancePolicyProvider $compliance,
        private readonly InstallationSiteProvider $sites,
    ) {}

    public function forInstallation(): RetentionPolicy
    {
        if ($this->policy instanceof RetentionPolicy) {
            return $this->policy;
        }

        $site = $this->sites->installationSite();

        if ($site === null) {
            throw InstallationSiteMissing::make();
        }

        $this->siteId = $site->id;

        return $this->policy = RetentionPolicy::of(
            legalRecordYears: $this->compliance->forSite($site->id)->retentionYears,
            technicalLogDays: Config::integer('compliance.retention.technical_log_days'),
            errorHistoryDays: Config::integer('compliance.retention.error_history_days'),
        );
    }

    public function snapshot(): RetentionPolicySnapshot
    {
        $policy = $this->forInstallation();

        return new RetentionPolicySnapshot(
            legalRecordYears: $policy->legalRecordYears,
            technicalLogDays: $policy->technicalLogDays,
            errorHistoryDays: $policy->errorHistoryDays,
            // `forInstallation()` acaba de resolverlo: aqui nunca es `null`.
            siteId: $this->siteId ?? 0,
        );
    }
}
