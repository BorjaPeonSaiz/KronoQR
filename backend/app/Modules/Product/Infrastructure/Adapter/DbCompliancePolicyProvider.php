<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Los umbrales **legales** del centro, leidos de `compliance_profiles`
 * (RF-PD-07, regla dura 14, ADR-025).
 *
 * **Por que existe ya, si la tarea que lo declara es la 5.2.** Por lo mismo que
 * {@see DbOperationalSettingsProvider} existe desde la 1.4: sin el, la deteccion
 * de RN-10, RN-11 y RN-12 (tarea 2.6) no tendria de donde sacar sus tres
 * umbrales, y la unica alternativa seria escribir 12 h, 9 h y 6 h como
 * constantes en PHP — que es exactamente lo que la regla dura 14 y ADR-017
 * prohiben. Lo que la 5.2 anade encima es la **edicion desde el panel**, la
 * resolucion en cascada por ambito y la auditoria de ese cambio
 * (`calculation_setting.changed`); la lectura, que es lo que el nucleo necesita,
 * es esto y no cambiara de forma.
 *
 * ## La cascada, que aqui son dos escalones y no tres
 *
 * `sites.compliance_profile_id` es **nullable** y significa «usa el perfil por
 * defecto de la instalacion» (`compliance_profiles.is_default`), tal como lo
 * dejo escrito la migracion de `sites`. Es lo que permite que un cliente con un
 * solo convenio no tenga que asignarlo centro a centro, y que el asistente de
 * puesta en marcha (RF-PD-03) cree centros antes de decidir el perfil. Un indice
 * unico parcial garantiza que solo hay un perfil por defecto, asi que la caida no
 * es ambigua.
 *
 * ## Las horas y los minutos
 *
 * El perfil se enuncia en **horas enteras** porque asi lo dice el negocio —«12 h
 * de descanso»— y asi lo nombra el doc 01 §5.5 (`min_rest_hours`). El dominio
 * razona en minutos porque esa es la unidad del calculo (`duration_minutes`), y
 * la conversion se hace aqui, en el borde: dejarla a cada consumidor es como
 * acaban dos reglas comparando unidades distintas.
 *
 * ## Sin valores por defecto en el codigo
 *
 * Si no hay perfil, **falla**. No cae en 12/9/6 escritos aqui: un umbral legal
 * escondido en PHP es indistinguible de uno configurado hasta que alguien compara
 * una alerta con el convenio. La fila `ES-hosteleria` la siembra la migracion de
 * `compliance_profiles`, que es donde el cliente puede cambiarla sin desplegar
 * nada.
 */
final class DbCompliancePolicyProvider implements CompliancePolicyProvider
{
    /**
     * Memoria por peticion.
     *
     * La revision diaria pide el perfil una vez, pero la vista de cumplimiento
     * (tarea 3.4) lo pedira por cada jornada de un informe. El proceso muere con
     * la peticion, asi que no es una cache con invalidacion: un cambio en el panel
     * tiene efecto en la siguiente.
     *
     * @var array<int, CompliancePolicy>
     */
    private array $resolved = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function forSite(int $siteId): CompliancePolicy
    {
        if (isset($this->resolved[$siteId])) {
            return $this->resolved[$siteId];
        }

        $profile = $this->profileFor($siteId) ?? $this->defaultProfile();

        if ($profile === null) {
            throw new RuntimeException(
                'No hay perfil de cumplimiento para el centro '.$siteId.' ni perfil por defecto en la '
                .'instalacion. Lo siembra la migracion de compliance_profiles (perfil ES-hosteleria); '
                .'revisa si se ha editado a mano.'
            );
        }

        return $this->resolved[$siteId] = new CompliancePolicy(
            minimumRestMinutes: $profile->min_rest_hours * 60,
            maximumDailyMinutes: $profile->max_daily_hours * 60,
            breakRequiredAfterMinutes: $profile->break_required_after_hours * 60,
            retentionYears: $profile->retention_years,
        );
    }

    /**
     * El perfil asignado al centro, si tiene uno.
     *
     * @return object{min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int}|null
     */
    private function profileFor(int $siteId): ?object
    {
        /** @var object{min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int}|null $profile */
        $profile = $this->connection->table('compliance_profiles')
            ->join('sites', 'sites.compliance_profile_id', '=', 'compliance_profiles.id')
            ->where('sites.id', $siteId)
            ->select($this->columns())
            ->first();

        return $profile;
    }

    /**
     * El perfil por defecto de la instalacion, que es lo que significa un centro
     * sin perfil asignado.
     *
     * @return object{min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int}|null
     */
    private function defaultProfile(): ?object
    {
        /** @var object{min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int}|null $profile */
        $profile = $this->connection->table('compliance_profiles')
            ->where('is_default', true)
            ->select($this->columns())
            ->first();

        return $profile;
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'compliance_profiles.min_rest_hours',
            'compliance_profiles.max_daily_hours',
            'compliance_profiles.break_required_after_hours',
            'compliance_profiles.retention_years',
        ];
    }
}
