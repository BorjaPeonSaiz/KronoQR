<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SettingKey;

/*
 * Los limites y los valores de serie del catalogo de configuracion (RF-PD-01,
 * ADR-017), fijados uno a uno.
 *
 * `SettingCatalogTest` comprueba que cada valor de serie cumple su propia
 * definicion, pero eso lo cumple tambien un catalogo con otros numeros: un
 * maximo de 25 horas de turno o un nombre de aplicacion de 61 caracteres
 * pasarian. Aqui esta, para cada clave, el valor de serie, el minimo, el maximo
 * y la longitud maxima que se documentan en `docs/cliente/configuracion.md` y
 * que el panel enseña. Cambiar cualquiera es una decision de producto, y esta
 * prueba obliga a tomarla a conciencia.
 *
 * Dominio puro: ni framework ni base de datos.
 */

it('fija el valor de serie y los limites de cada clave', function (
    SettingKey $key,
    int|string $default,
    ?int $minimum,
    ?int $maximum,
    int $maximumLength,
): void {
    $definition = $key->definition();

    expect($definition->default)->toBe($default)
        ->and($definition->minimum)->toBe($minimum)
        ->and($definition->maximum)->toBe($maximum)
        ->and($definition->maximumLength)->toBe($maximumLength);
})->with([
    // Umbrales operativos del fichaje (Anexo B del doc 02).
    'turno anomalo, horas' => [SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, 12, 1, 24, 0],
    'anti-rebote, segundos' => [SettingKey::ATTENDANCE_DEBOUNCE_SECONDS, 60, 0, 3600, 0],
    'desfase de reloj, minutos' => [SettingKey::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES, 15, 1, 1440, 0],
    'transito entre quioscos, segundos' => [SettingKey::ATTENDANCE_MIN_TRANSIT_SECONDS, 120, 0, 3600, 0],
    'ventana de patron, segundos' => [SettingKey::ATTENDANCE_PATTERN_WINDOW_SECONDS, 10, 0, 300, 0],
    'repeticiones de patron' => [SettingKey::ATTENDANCE_PATTERN_MIN_REPEATS, 3, 1, 30, 0],
    'margen de futuro, minutos' => [SettingKey::ATTENDANCE_FUTURE_TOLERANCE_MINUTES, 5, 0, 60, 0],
    // Quiosco y cuadro de impacto.
    'silencio antes de actualizar, minutos' => [SettingKey::KIOSK_UPDATE_QUIET_MINUTES, 10, 0, 120, 0],
    'horas manuales de referencia' => [SettingKey::BASELINE_MANUAL_HOURS_PER_MONTH, 0, 0, 10000, 0],
    // Textos: la longitud maxima es lo que cabe en la pantalla o en la columna.
    'nombre de la aplicacion' => [SettingKey::BRANDING_APP_NAME, 'KronoQR', null, null, 60],
    'ruta del logotipo' => [SettingKey::BRANDING_LOGO_PATH, '', null, null, 512],
    'color de acento' => [SettingKey::BRANDING_ACCENT_COLOR, '#b8542a', null, null, 7],
    'codigo de servicio del quiosco' => [SettingKey::KIOSK_SERVICE_CODE, '', null, null, 12],
    'ventana de actualizacion' => [SettingKey::KIOSK_UPDATE_WINDOW, '03:00-05:00', null, null, 11],
    'responsable del tratamiento' => [SettingKey::PRIVACY_CONTROLLER_NAME, '', null, null, 160],
    'politica de privacidad' => [SettingKey::PRIVACY_POLICY_URL, '', null, null, 512],
])->group('RF-PD-01', 'RF-PD-08', 'RF-KI-07', 'RF-KI-08', 'RF-KI-09');

it('ofrece solo el español de serie, entre los dos idiomas que el producto trae', function (): void {
    // Bloque 26 de la 2.2.1: con un unico idioma activo no hay selector.
    $available = SettingKey::LOCALE_AVAILABLE->definition();
    $default = SettingKey::LOCALE_DEFAULT->definition();

    expect($available->default)->toBe(['es'])
        ->and($available->allowed)->toBe(['es', 'en'])
        ->and($default->default)->toBe('es')
        ->and($default->allowed)->toBe(['es', 'en']);
})->group('RF-PD-01', 'RF-KI-05');
