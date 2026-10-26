<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Query;

use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cuantos años se conserva el registro de jornada, para el aviso de privacidad
 * (`GET /api/v1/branding`, `privacy_notice.retention_years`; 2.3.0).
 *
 * El art. 13.2.a RGPD obliga a decir el plazo de conservacion en el aviso, y el
 * quiosco lo tenia escrito en sus textos («4 años»). El plazo es el
 * `retention_years` del perfil de cumplimiento del centro (RF-PD-07, RL-02), y
 * un cliente con otro convenio o en otra jurisdiccion lo tiene distinto: se lee
 * del perfil por el puerto, nunca de una constante (reglas duras 13 y 14).
 *
 * ## `null` en lugar de fallar
 *
 * La marca es publica y la pide la tablet antes de que nadie fiche: que el
 * perfil no se pueda leer —instalacion sin centro todavia, base caida a medias—
 * no puede tumbar la pantalla de espera del quiosco (regla dura 19). Sin plazo
 * resoluble se devuelve `null` y el aviso lo dice sin cifra. El fallo queda en
 * el log, sin valores ni datos personales (regla dura 21).
 */
final readonly class ReadRecordRetentionYears
{
    public function __construct(
        private InstallationSiteProvider $sites,
        private CompliancePolicyProvider $compliance,
        private LoggerInterface $logger,
    ) {}

    public function handle(): ?int
    {
        return UseCaseInstrumentation::run('product.read_record_retention_years', fn (): ?int => $this->doHandle());
    }

    private function doHandle(): ?int
    {
        try {
            $site = $this->sites->installationSite();

            if (! $site instanceof InstallationSite) {
                return null;
            }

            return $this->compliance->forSite($site->id)->retentionYears;
        } catch (Throwable $failure) {
            $this->logger->warning('product.retention_years_unresolved', [
                'reason' => $failure::class,
            ]);

            return null;
        }
    }
}
