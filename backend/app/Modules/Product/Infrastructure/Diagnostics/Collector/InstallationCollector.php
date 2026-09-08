<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\SettingKey;
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
 */
final readonly class InstallationCollector implements DiagnosticsCollector
{
    public function __construct(
        private ConnectionInterface $database,
        private GetSettingsHandler $settings,
        private ComplianceProfileRepository $profiles,
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
