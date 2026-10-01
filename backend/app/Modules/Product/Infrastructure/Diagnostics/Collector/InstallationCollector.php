<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Seccion `installation`: **que version, con que reglas y en que huso**
 * (doc 02 §11.6.6).
 *
 * Es lo primero que mira soporte y responde a las tres preguntas con las que
 * empieza cualquier incidencia de calculo de horas: que version corre, con que
 * umbrales legales se calcula y en que zona horaria se atribuyen las jornadas
 * (RN-05).
 *
 * ## Sin el nombre del hotel y sin el del centro
 *
 * `sites.timezone` si; `sites.name` no. Y `BRANDING_APP_NAME` tampoco. El nombre
 * comercial del cliente no hace falta para diagnosticar nada y el paquete existe
 * precisamente para que el fabricante no reciba lo que no necesita (ADR-020).
 * Del perfil de cumplimiento sale la **jurisdiccion y sus umbrales**, no su
 * nombre: «ES-hosteleria» es una regla, «Convenio del Hotel Fulanito» seria el
 * cliente.
 *
 * ## `volume`: solo numeros, para no pedir una segunda ronda (PR14)
 *
 * «La nomina sale vacia» tiene dos lecturas opuestas: que no hay nada que
 * exportar o que lo hay y el fichero no lo recoge. Cuatro recuentos las
 * separan sin enviar un solo dato de nadie: personas en activo, escaneos de los
 * ultimos 30 dias, tramos en vigor con jornada en esos 30 dias e incidencias
 * abiertas. **Recuentos y nada mas**: ningun identificador, ninguna fecha
 * concreta, ningun desglose por persona o departamento.
 *
 * Los tramos se cuentan aparte de los escaneos porque el alta manual del panel
 * crea tramos sin escaneo: un hotel que ficha a mano tiene cero escaneos y una
 * nomina perfectamente llena. Los tramos `voided` y `superseded` no cuentan:
 * son versiones conservadas (RN-13), no horas.
 *
 * La ventana se mide con el puerto `Clock` en UTC; en la frontera del dia 30 el
 * recuento puede diferir en unas horas de lo que vea el cliente en su zona, y
 * para un orden de magnitud da igual.
 */
final readonly class InstallationCollector implements DiagnosticsCollector
{
    /** Ventana de los recuentos de actividad, en dias. */
    public const int VOLUME_WINDOW_DAYS = 30;

    public function __construct(
        private ConnectionInterface $database,
        private GetSettingsHandler $settings,
        private ComplianceProfileRepository $profiles,
        private Clock $clock,
        private string $productVersion,
        private string $environment,
        private string $applicationTimezone,
    ) {}

    public function section(): string
    {
        return 'installation';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $resolved = $this->settings->handle();

        return [
            'product_version' => $this->productVersion,
            'php_version' => PHP_VERSION,
            'app_env' => $this->environment,
            // Regla dura 3: siempre `UTC`. Que aparezca aqui no es redundante,
            // es la prueba de que lo sigue siendo — `doctor` lo comprueba y esta
            // linea deja constancia en el paquete.
            'app_timezone' => $this->applicationTimezone,
            'default_locale' => $resolved->text(SettingKey::LOCALE_DEFAULT),
            'available_locales' => $resolved->textList(SettingKey::LOCALE_AVAILABLE),
            'site' => $this->site(),
            'compliance_profile' => $this->complianceProfile(),
            'volume' => $this->volume(),
        ];
    }

    /**
     * Los recuentos de volumen. Ver el docblock de la clase.
     *
     * @return array{active_employees: int, scan_events_last_30_days: int, shift_entries_last_30_days: int, open_incidents: int}
     */
    private function volume(): array
    {
        $since = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('-'.self::VOLUME_WINDOW_DAYS.' days');

        return [
            'active_employees' => $this->database->table('employees')->where('status', 'active')->count(),
            'scan_events_last_30_days' => $this->database->table('scan_events')
                ->where('occurred_at', '>=', $since->format('Y-m-d\TH:i:s.uP'))
                ->count(),
            'shift_entries_last_30_days' => $this->database->table('shift_entries')
                ->where('work_date', '>=', $since->format('Y-m-d'))
                ->whereNotIn('status', ['voided', 'superseded'])
                ->count(),
            'open_incidents' => $this->database->table('incidents')->where('status', 'open')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function site(): array
    {
        /** @var object{id: int, timezone: string}|null $site */
        $site = $this->database->table('sites')->select('id', 'timezone')->orderBy('id')->first();

        return $site === null
            ? ['status' => 'not_configured']
            // El identificador si, porque el resto del paquete lo referencia
            // (`worked_minutes_total{site=1}`); el nombre no.
            : ['id' => (int) $site->id, 'timezone' => (string) $site->timezone];
    }

    /**
     * @return array<string, mixed>
     */
    private function complianceProfile(): array
    {
        /** @var object{id: int}|null $site */
        $site = $this->database->table('sites')->select('id')->orderBy('id')->first();

        $profile = $site === null ? null : $this->profiles->forSite((int) $site->id);

        if (! $profile instanceof ComplianceProfileSnapshot) {
            return ['status' => 'not_configured'];
        }

        return [
            'jurisdiction' => $profile->jurisdiction,
            'is_default' => $profile->isDefault,
            'source' => $profile->source->value,
            'min_rest_hours' => $profile->minRestHours,
            'max_daily_hours' => $profile->maxDailyHours,
            'max_weekly_hours' => $profile->maxWeeklyHours,
            'break_required_after_hours' => $profile->breakRequiredAfterHours,
            'week_starts_on' => $profile->weekStartsOn,
            'retention_years' => $profile->retentionYears,
            'holiday_calendar_days' => \count($profile->holidayCalendar),
        ];
    }
}
