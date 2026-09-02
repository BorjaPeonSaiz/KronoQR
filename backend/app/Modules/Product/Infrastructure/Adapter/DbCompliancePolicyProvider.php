<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\CompliancePolicyProvider;
use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;
use App\Modules\Shared\Domain\ValueObject\HolidayCalendar;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
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
 * auditoria de ese cambio (`calculation_setting.changed`) y los tres campos que
 * faltaban del perfil —jornada semanal, inicio de semana y festivos—; la lectura,
 * que es lo que el nucleo necesita, es esto y no cambia de forma.
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

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LoggerInterface $logger,
    ) {}

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
            maximumWeeklyMinutes: $profile->max_weekly_hours * 60,
            weekStartsOn: $profile->week_starts_on,
            holidayCalendar: $this->decodeCalendar($profile->id, $profile->holiday_calendar),
        );
    }

    /**
     * El perfil asignado al centro, si tiene uno.
     *
     * @return object{id: int, min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int, max_weekly_hours: int, week_starts_on: int, holiday_calendar: string}|null
     */
    private function profileFor(int $siteId): ?object
    {
        /** @var object{id: int, min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int, max_weekly_hours: int, week_starts_on: int, holiday_calendar: string}|null $profile */
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
     * @return object{id: int, min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int, max_weekly_hours: int, week_starts_on: int, holiday_calendar: string}|null
     */
    private function defaultProfile(): ?object
    {
        /** @var object{id: int, min_rest_hours: int, max_daily_hours: int, break_required_after_hours: int, retention_years: int, max_weekly_hours: int, week_starts_on: int, holiday_calendar: string}|null $profile */
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
            'compliance_profiles.id',
            'compliance_profiles.min_rest_hours',
            'compliance_profiles.max_daily_hours',
            'compliance_profiles.break_required_after_hours',
            'compliance_profiles.retention_years',
            'compliance_profiles.max_weekly_hours',
            'compliance_profiles.week_starts_on',
            'compliance_profiles.holiday_calendar',
        ];
    }

    /**
     * El calendario de festivos, que PostgreSQL entrega como el texto del JSONB.
     *
     * **Tolerante, y esto no es una comodidad: es lo que impide que un festivo
     * mal escrito apague dos reglas legales.** Este metodo esta en el camino de
     * la pasada nocturna de deteccion, que resuelve la politica una sola vez
     * antes del bucle y sin `try`. Antes de la revision de la 5.2, un
     * `'["navidad"]'` escrito a mano pasaba el filtro de aqui —que solo miraba
     * que fueran cadenas— y estallaba dentro del objeto de valor: la pasada moria
     * entera y RN-10 y RN-11 dejaban de evaluarse en toda la instalacion, y la
     * purga por retencion caia con ella. Lo que se pierde ahora es la fecha mala,
     * que hoy no la lee ninguna regla; lo que no se pierde es el resto del perfil.
     *
     * **El descarte no es silencioso**: deja un `warning` con el identificador
     * del perfil y cuantas entradas se descartaron. Ni las fechas ni el nombre
     * viajan al log —viaja a Loki y al paquete de diagnostico (ADR-020)— porque
     * el habito de la regla dura 21 vale tambien para lo que no es dato personal.
     *
     * @return list<string>
     */
    private function decodeCalendar(int $profileId, string $raw): array
    {
        $calendar = HolidayCalendar::fromStoredJson($raw);

        if (! $calendar->isClean()) {
            $this->logger->warning('product.compliance_profile_calendar_discarded', [
                'profile_id' => $profileId,
                'rejected' => count($calendar->rejected),
                'had_duplicates' => $calendar->hadDuplicates,
            ]);
        }

        return $calendar->days;
    }
}
