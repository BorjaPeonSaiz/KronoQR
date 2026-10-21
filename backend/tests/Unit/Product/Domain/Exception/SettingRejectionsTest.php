<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSettingValue;
use App\Modules\Product\Domain\Exception\UnknownSettingKey;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use Tests\Support\Shared\RejectionTranslations;

/*
 * Lo que dice un rechazo de la configuracion de la instalacion (RF-PD-01,
 * ADR-017).
 *
 * Cada rechazo lleva dos lecturas. La tecnica, en ingles, es `getMessage()` y va
 * al log. La de la persona que corrige el campo sale de `translationKey` y
 * `parameters`, que el borde HTTP traduce en el idioma negociado: si falta un
 * parametro, el `422` le dice «admite de 1 a :maximum».
 */

dataset('rechazos de un valor de configuracion', [
    'no es un entero' => [
        fn (): InvalidSettingValue => InvalidSettingValue::notAnInteger(SettingKey::ATTENDANCE_DEBOUNCE_SECONDS, 'string'),
        'settings.errors.not_integer',
        ['key' => 'ATTENDANCE_DEBOUNCE_SECONDS', 'received' => 'string'],
        'Setting "ATTENDANCE_DEBOUNCE_SECONDS" expects an integer, got string.',
    ],
    'no es texto' => [
        fn (): InvalidSettingValue => InvalidSettingValue::notText(SettingKey::BRANDING_APP_NAME, 'int'),
        'settings.errors.not_text',
        ['key' => 'BRANDING_APP_NAME', 'received' => 'int'],
        'Setting "BRANDING_APP_NAME" expects a string, got int.',
    ],
    'no es una lista' => [
        fn (): InvalidSettingValue => InvalidSettingValue::notAList(SettingKey::LOCALE_AVAILABLE, 'string'),
        'settings.errors.not_list',
        ['key' => 'LOCALE_AVAILABLE', 'received' => 'string'],
        'Setting "LOCALE_AVAILABLE" expects a list, got string.',
    ],
    'no es una lista de textos' => [
        fn (): InvalidSettingValue => InvalidSettingValue::notAListOfText(SettingKey::LOCALE_AVAILABLE, 'int'),
        'settings.errors.not_list_of_text',
        ['key' => 'LOCALE_AVAILABLE', 'received' => 'int'],
        'Setting "LOCALE_AVAILABLE" expects a list of strings, got int.',
    ],
    'fuera de rango' => [
        fn (): InvalidSettingValue => InvalidSettingValue::outOfRange(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, 25, 1, 24),
        'settings.errors.out_of_range',
        ['key' => 'ATTENDANCE_MAX_SHIFT_HOURS', 'minimum' => 1, 'maximum' => 24, 'value' => 25],
        'Setting "ATTENDANCE_MAX_SHIFT_HOURS" accepts 1 to 24, got 25.',
    ],
    'vacio' => [
        fn (): InvalidSettingValue => InvalidSettingValue::notEmpty(SettingKey::BRANDING_APP_NAME),
        'settings.errors.not_empty',
        ['key' => 'BRANDING_APP_NAME'],
        'Setting "BRANDING_APP_NAME" does not accept an empty value.',
    ],
    'demasiado largo' => [
        fn (): InvalidSettingValue => InvalidSettingValue::tooLong(SettingKey::BRANDING_APP_NAME, 81, 80),
        'settings.errors.too_long',
        ['key' => 'BRANDING_APP_NAME', 'maximum' => 80, 'length' => 81],
        'Setting "BRANDING_APP_NAME" accepts up to 80 characters, got 81.',
    ],
    'mal formado' => [
        fn (): InvalidSettingValue => InvalidSettingValue::malformed(SettingKey::BRANDING_ACCENT_COLOR, '#RRGGBB'),
        'settings.errors.malformed',
        ['key' => 'BRANDING_ACCENT_COLOR', 'shape' => '#RRGGBB'],
        'Setting "BRANDING_ACCENT_COLOR" has a malformed value; expected #RRGGBB.',
    ],
    'repetido' => [
        fn (): InvalidSettingValue => InvalidSettingValue::duplicated(SettingKey::LOCALE_AVAILABLE),
        'settings.errors.duplicated',
        ['key' => 'LOCALE_AVAILABLE'],
        'Setting "LOCALE_AVAILABLE" does not accept repeated values.',
    ],
    'fuera de los permitidos' => [
        fn (): InvalidSettingValue => InvalidSettingValue::notAllowed(SettingKey::PAYROLL_EXPORT_ENCODING, 'latin2', ['utf-8', 'windows-1252']),
        'settings.errors.not_allowed',
        ['key' => 'PAYROLL_EXPORT_ENCODING', 'allowed' => 'utf-8, windows-1252', 'value' => 'latin2'],
        'Setting "PAYROLL_EXPORT_ENCODING" only accepts utf-8, windows-1252, got latin2.',
    ],
    'idioma por defecto que no esta disponible' => [
        fn (): InvalidSettingValue => InvalidSettingValue::defaultLocaleIsNotAvailable('fr', 'es, en'),
        'settings.errors.default_locale_not_available',
        ['default' => 'fr', 'available' => 'es, en'],
        'Default locale "fr" is not among the available locales (es, en).',
    ],
]);

it('dice que clave fallo, por que y con que valores', function (
    InvalidSettingValue $rechazo,
    string $clave,
    array $parametros,
    string $mensaje,
): void {
    expect($rechazo->translationKey)->toBe($clave)
        ->and($rechazo->parameters)->toBe($parametros)
        ->and($rechazo->getMessage())->toBe($mensaje);
})->with('rechazos de un valor de configuracion')->group('RF-PD-01');

it('tiene texto en español y en ingles con todos sus marcadores sustituidos', function (InvalidSettingValue $rechazo): void {
    expect(RejectionTranslations::problemsWith($rechazo->translationKey, $rechazo->parameters))->toBe([]);
})->with('rechazos de un valor de configuracion')->group('RF-PD-01', 'RF-KI-05');

it('dice que clave no esta en el catalogo', function (): void {
    $rechazo = new UnknownSettingKey('ATTENDANCE_COFFEE_BREAKS');

    expect($rechazo->translationKey)->toBe('settings.unknown_key')
        ->and($rechazo->parameters)->toBe(['key' => 'ATTENDANCE_COFFEE_BREAKS'])
        ->and($rechazo->getMessage())->toBe(
            'Setting key "ATTENDANCE_COFFEE_BREAKS" is not in the installation catalogue. '
            .'The catalogue is SettingKey: a key that is not there is read by nobody.',
        );
})->group('RF-PD-01');

it('tiene texto en español y en ingles para la clave que no esta en el catalogo', function (): void {
    $rechazo = new UnknownSettingKey('ATTENDANCE_COFFEE_BREAKS');

    expect(RejectionTranslations::problemsWith($rechazo->translationKey, $rechazo->parameters))->toBe([]);
})->group('RF-PD-01', 'RF-KI-05');
