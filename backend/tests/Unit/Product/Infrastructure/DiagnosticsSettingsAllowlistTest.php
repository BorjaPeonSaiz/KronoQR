<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\SettingsAnomalyReporter;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\ConfigurationCollector;
use App\Modules\Product\Infrastructure\Diagnostics\DiagnosticsSettingsAllowlist;

/*
 * Los ajustes guardados de la instalacion en el paquete de diagnostico
 * (RF-PD-09, ADR-020, PR14).
 *
 * La misma idea que `DiagnosticsConfigurationAllowlistTest` para el `.env`:
 * **lista de permitidos**, y una prueba que obliga a clasificar cada clave nueva
 * del catalogo antes de que pueda viajar o dejar de viajar sin que nadie lo
 * decida.
 */

/** Valores guardados reconocibles: si alguno de los excluidos sale, se sabe cual. */
const DIAG_SETTINGS_GUARDADOS = [
    'ATTENDANCE_MAX_SHIFT_HOURS' => 14,
    'ATTENDANCE_FUTURE_TOLERANCE_MINUTES' => 10,
    'PAYROLL_EXPORT_COLUMNS' => ['employee_code=Codigo Gestoria Perez', 'worked_hours=Horas', 'first_name'],
    'PAYROLL_EXPORT_DELIMITER' => 'semicolon',
    'WEEKLY_SUMMARY_EMAIL' => 'enabled',
    'BRANDING_APP_NAME' => 'Hotel Miramar Fichajes',
    'BRANDING_ACCENT_COLOR' => '#123456',
    'KIOSK_SERVICE_CODE' => '73519046',
    'BASELINE_MANUAL_HOURS_PER_MONTH' => 37,
];

/** @param  array<string, mixed>  $stored */
function diagSettingsHandlerCon(array $stored): GetSettingsHandler
{
    $repository = new class($stored) implements SettingsRepository
    {
        /** @param  array<string, mixed>  $stored */
        public function __construct(private array $stored) {}

        public function storedValues(): array
        {
            return $this->stored;
        }

        public function storedValuesForWrite(): array
        {
            return $this->stored;
        }

        public function save(array $values, ?int $actorUserId): void {}
    };

    $reporter = new class implements SettingsAnomalyReporter
    {
        public function report(ResolvedSettings $settings): void {}
    };

    return new GetSettingsHandler($repository, $reporter);
}

it('clasifica cada clave del catalogo exactamente una vez: viaja o no viaja, y por decision', function (): void {
    $allowed = array_map(static fn (SettingKey $key): string => $key->value, DiagnosticsSettingsAllowlist::ALLOWED);
    $excluded = array_map(static fn (SettingKey $key): string => $key->value, DiagnosticsSettingsAllowlist::EXCLUDED);
    $catalog = array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases());

    $unclassified = array_values(array_diff($catalog, $allowed, $excluded));

    expect($unclassified)->toBe([], 'Estas claves del catalogo no estan ni en ALLOWED ni en EXCLUDED de '
        .'DiagnosticsSettingsAllowlist: '.implode(', ', $unclassified).'. Decide si viajan en el paquete.')
        ->and(array_intersect($allowed, $excluded))->toBe([])
        ->and(array_unique($allowed))->toHaveCount(\count($allowed))
        ->and(array_unique($excluded))->toHaveCount(\count($excluded));
})->group('RF-PD-09', 'RS-08');

it('ninguna clave confidencial viaja', function (): void {
    foreach (DiagnosticsSettingsAllowlist::ALLOWED as $key) {
        expect($key->definition()->confidential)->toBeFalse('La clave '.$key->value.' es confidencial y esta permitida.');
    }

    expect(DiagnosticsSettingsAllowlist::EXCLUDED)->toContain(SettingKey::KIOSK_SERVICE_CODE);
})->group('RF-PD-09', 'RS-08');

it('deja fuera la marca y el dato de negocio, que identifican al cliente sin explicar ningun fallo', function (SettingKey $key): void {
    expect(DiagnosticsSettingsAllowlist::EXCLUDED)->toContain($key)
        ->and(DiagnosticsSettingsAllowlist::apply(ResolvedSettings::resolve(DIAG_SETTINGS_GUARDADOS)))
        ->not->toHaveKey($key->value);
})->with([
    SettingKey::BRANDING_APP_NAME,
    SettingKey::BRANDING_LOGO_PATH,
    SettingKey::BRANDING_ACCENT_COLOR,
    SettingKey::KIOSK_SERVICE_CODE,
    SettingKey::BASELINE_MANUAL_HOURS_PER_MONTH,
])->group('RF-PD-09', 'RS-08');

it('lleva el valor resuelto y si es guardado o de serie', function (): void {
    $settings = DiagnosticsSettingsAllowlist::apply(ResolvedSettings::resolve(DIAG_SETTINGS_GUARDADOS));

    $sortedKeys = array_keys($settings);
    sort($sortedKeys, SORT_STRING);

    expect($settings['ATTENDANCE_MAX_SHIFT_HOURS'])->toBe(['value' => 14, 'source' => 'stored'])
        ->and($settings['ATTENDANCE_FUTURE_TOLERANCE_MINUTES'])->toBe(['value' => 10, 'source' => 'stored'])
        ->and($settings['PAYROLL_EXPORT_DELIMITER'])->toBe(['value' => 'semicolon', 'source' => 'stored'])
        ->and($settings['WEEKLY_SUMMARY_EMAIL'])->toBe(['value' => 'enabled', 'source' => 'stored'])
        // Lo que nadie ha tocado sale con el valor de serie, y dicho asi.
        ->and($settings['ATTENDANCE_DEBOUNCE_SECONDS']['source'])->toBe('default')
        ->and($settings['KIOSK_UPDATE_WINDOW'])->toBe(['value' => '03:00-05:00', 'source' => 'default'])
        // Ordenado, para comparar dos paquetes con diff.
        ->and(array_keys($settings))->toBe($sortedKeys)
        ->and($settings)->toHaveCount(\count(DiagnosticsSettingsAllowlist::ALLOWED));
})->group('RF-PD-09');

it('de las columnas de nomina viaja el identificador, nunca el rotulo que escribio el cliente', function (): void {
    $settings = DiagnosticsSettingsAllowlist::apply(ResolvedSettings::resolve(DIAG_SETTINGS_GUARDADOS));

    expect($settings['PAYROLL_EXPORT_COLUMNS'])->toBe([
        'value' => ['employee_code', 'worked_hours', 'first_name'],
        'source' => 'stored',
    ]);

    $json = (string) json_encode($settings);

    expect($json)->not->toContain('Gestoria')
        ->and($json)->not->toContain('Perez')
        ->and($json)->not->toContain('Horas');
})->group('RF-PD-09', 'RS-08');

it('una fila invalida sale con el valor de serie y no con lo que habia guardado', function (): void {
    // `invalid_keys` ya dice que la fila se descarto; aqui sale lo que RIGE.
    $settings = DiagnosticsSettingsAllowlist::apply(ResolvedSettings::resolve(['ATTENDANCE_MAX_SHIFT_HOURS' => 'doce']));

    expect($settings['ATTENDANCE_MAX_SHIFT_HOURS'])->toBe(['value' => 12, 'source' => 'default']);
})->group('RF-PD-09');

it('la seccion configuration lleva los ajustes guardados y ni un valor de los excluidos', function (): void {
    $collector = new ConfigurationCollector(
        settings: diagSettingsHandlerCon(DIAG_SETTINGS_GUARDADOS),
        environment: ['APP_ENV' => 'production', 'ATTENDANCE_MAX_SHIFT_HOURS' => '12', 'LICENSE_KEY' => 'secreto'],
    );

    $section = $collector->collect(DiagnosticsOptions::anonymized());
    $json = (string) json_encode($section);

    expect($section)->toHaveKeys(['env', 'installation_settings', 'invalid_keys', 'unknown_keys', 'env_differs_from_database'])
        ->and($section['installation_settings'])->toHaveKey('PAYROLL_EXPORT_COLUMNS')
        ->and($json)->not->toContain('Hotel Miramar')
        ->and($json)->not->toContain('#123456')
        ->and($json)->not->toContain('73519046')
        ->and($json)->not->toContain('KIOSK_SERVICE_CODE')
        ->and($json)->not->toContain('BASELINE_MANUAL_HOURS_PER_MONTH')
        ->and($json)->not->toContain('LICENSE_KEY')
        ->and($json)->not->toContain('secreto');
})->group('RF-PD-09', 'RS-08');
