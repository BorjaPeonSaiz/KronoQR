<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/**
 * Que ajustes guardados de la instalacion (`installation_settings`, lo que se
 * edita desde el panel y devuelve `GET /api/v1/settings`) entran en el paquete
 * de diagnostico, y con que forma (RF-PD-09, ADR-020, PR14).
 *
 * ## Por que hace falta, ademas del `.env`
 *
 * Porque **la base de datos manda** sobre el `.env` en cuanto alguien guarda un
 * valor desde el panel (ADR-017), y la seccion `configuration` solo llevaba el
 * `.env` y la LISTA de claves que difieren, sin valores. Una incidencia del
 * tipo «la nomina sale vacia» o «el fichero no lo lee la gestoria» obligaba a
 * pedir una segunda ronda de capturas: el formato de nomina vive solo aqui.
 *
 * ## Lista de permitidos por clave, igual que la del entorno
 *
 * Cada clave del catalogo esta **clasificada a mano**: o en {@see self::ALLOWED}
 * o en {@see self::EXCLUDED} con su motivo. `DiagnosticsSettingsAllowlistTest`
 * falla si una clave nueva de {@see SettingKey} no esta en ninguna de las dos,
 * asi que nadie puede añadir un ajuste al catalogo sin decidir si viaja.
 *
 * ## Lo que no viaja, y por que
 *
 * - `BRANDING_APP_NAME`, `BRANDING_LOGO_PATH` y `BRANDING_ACCENT_COLOR`: son la
 *   identidad del cliente —su nombre comercial, una ruta que suele llevarlo y su
 *   color corporativo— y no explican ningun fallo; si el logotipo no sale, lo
 *   dice `doctor`.
 * - `KIOSK_SERVICE_CODE`: la unica clave `confidential` del catalogo. Ni su
 *   valor ni si esta puesto.
 * - `BASELINE_MANUAL_HOURS_PER_MONTH`: un dato de negocio del cliente que no
 *   gobierna ningun calculo del registro. Minimizacion: si no hace falta, no
 *   viaja.
 *
 * ## Los rotulos de nomina tampoco
 *
 * `PAYROLL_EXPORT_COLUMNS` guarda entradas `id=Rotulo`. El identificador es del
 * catalogo cerrado del producto (`PayrollColumn`) y es lo que explica que
 * columnas salen y en que orden; el **rotulo** es texto libre del cliente y
 * puede llevar cualquier cosa, desde el nombre de su gestoria hasta el de una
 * persona. Viaja solo el identificador.
 */
final class DiagnosticsSettingsAllowlist
{
    /**
     * Claves que viajan, con su valor resuelto y su procedencia.
     *
     * @var list<SettingKey>
     */
    public const array ALLOWED = [
        SettingKey::ATTENDANCE_MAX_SHIFT_HOURS,
        SettingKey::ATTENDANCE_DEBOUNCE_SECONDS,
        SettingKey::ATTENDANCE_MAX_CLOCK_SKEW_MINUTES,
        SettingKey::ATTENDANCE_MIN_TRANSIT_SECONDS,
        SettingKey::ATTENDANCE_PATTERN_WINDOW_SECONDS,
        SettingKey::ATTENDANCE_PATTERN_MIN_REPEATS,
        SettingKey::ATTENDANCE_BREAK_CLOCKING,
        SettingKey::ATTENDANCE_FUTURE_TOLERANCE_MINUTES,
        SettingKey::LOCALE_DEFAULT,
        SettingKey::LOCALE_AVAILABLE,
        SettingKey::PAYROLL_EXPORT_COLUMNS,
        SettingKey::PAYROLL_EXPORT_DELIMITER,
        SettingKey::PAYROLL_EXPORT_HOURS_FORMAT,
        SettingKey::PAYROLL_EXPORT_DATE_FORMAT,
        SettingKey::PAYROLL_EXPORT_ENCODING,
        SettingKey::PAYROLL_EXPORT_HEADER_ROW,
        // `enabled`/`disabled`: si sale el resumen semanal. NO la direccion de
        // nadie, que el producto no guarda en esta clave.
        SettingKey::WEEKLY_SUMMARY_EMAIL,
        SettingKey::KIOSK_UPDATE_WINDOW,
        SettingKey::KIOSK_UPDATE_QUIET_MINUTES,
    ];

    /**
     * Claves que no viajan. Ver el docblock de la clase para el motivo de cada
     * una.
     *
     * @var list<SettingKey>
     */
    public const array EXCLUDED = [
        SettingKey::BRANDING_APP_NAME,
        SettingKey::BRANDING_LOGO_PATH,
        SettingKey::BRANDING_ACCENT_COLOR,
        SettingKey::KIOSK_SERVICE_CODE,
        SettingKey::BASELINE_MANUAL_HOURS_PER_MONTH,
    ];

    /**
     * Los ajustes permitidos, ordenados por clave.
     *
     * `source` es `stored` si rige una fila guardada y `default` si rige el valor
     * de serie —incluido el caso de una fila invalida, que ademas aparece en
     * `invalid_keys`—. Con eso soporte distingue «el cliente lo cambio» de «nunca
     * lo toco», que suele ser la mitad de la respuesta.
     *
     * @return array<string, array{value: int|string|list<string>, source: 'stored'|'default'}>
     */
    public static function apply(ResolvedSettings $settings): array
    {
        $allowed = [];

        foreach (self::ALLOWED as $key) {
            $setting = $settings->get($key);

            $allowed[$key->value] = [
                'value' => self::valueOf($setting),
                'source' => $setting->isProductDefault ? 'default' : 'stored',
            ];
        }

        ksort($allowed, SORT_STRING);

        return $allowed;
    }

    /** @return int|string|list<string> */
    private static function valueOf(SettingValue $setting): int|string|array
    {
        $value = $setting->value();

        if (! is_array($value)) {
            return $value;
        }

        $items = $setting->asTextList();

        if ($setting->key !== SettingKey::PAYROLL_EXPORT_COLUMNS) {
            return $items;
        }

        // Solo el identificador de columna; el rotulo es texto libre del cliente.
        return array_map(
            static fn (string $entry): string => trim(explode(PayrollLayout::LABEL_SEPARATOR, $entry, 2)[0]),
            $items,
        );
    }
}
