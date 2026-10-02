<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\LowContrastAccentColor;
use App\Modules\Product\Domain\Exception\ProductDomainException;
use App\Modules\Product\Domain\Policy\AccentContrastPolicy;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Domain\ValueObject\HexColor;

/*
 * Un acento sin contraste sobre las superficies claras solo se guarda confirmado (MB2).
 *
 * El minimo es el de texto normal, 4,5:1, porque el acento sustituye a
 * `primary-strong` —texto de enlace y fondo del boton principal— y es el nombre
 * de la cabecera del PDF sellado. Confirmacion y no rechazo duro: doc 06 §7,
 * «contraste avisado, no impuesto».
 */

function accentContrastValue(string $color): SettingValue
{
    return SettingValue::of(SettingKey::BRANDING_ACCENT_COLOR, $color);
}

it('exige confirmar un acento que no llega a 4,5:1 sobre las superficies claras', function (string $color): void {
    expect(fn () => AccentContrastPolicy::assertAcceptable(false, accentContrastValue($color)))
        ->toThrow(LowContrastAccentColor::class);
})->with([
    'amarillo palido' => ['#ffe14d'],
    'casi blanco' => ['#fafafa'],
    'gris medio' => ['#808080'],
    'rojo puro' => ['#ff0000'],
    'un pelo por debajo del minimo (4,47:1)' => ['#737373'],
    // Llega sobre blanco (4,54:1) pero no sobre el fondo de pagina (4,28:1).
    'el gris de referencia de WCAG' => ['#767676'],
])->group('RF-PD-08');

it('deja pasar sin confirmacion un acento que llega', function (string $color): void {
    AccentContrastPolicy::assertAcceptable(false, accentContrastValue($color));

    expect(true)->toBeTrue();
})->with([
    'el acento de serie (4,56:1 sobre surface)' => ['#b8542a'],
    'justo por encima del minimo (4,53:1)' => ['#727272'],
    'un azul oscuro' => ['#0f5c8c'],
])->group('RF-PD-08');

it('deja pasar cualquier acento cuando viene confirmado', function (): void {
    AccentContrastPolicy::assertAcceptable(true, accentContrastValue('#ffffff'));

    expect(true)->toBeTrue();
})->group('RF-PD-08');

it('no mira las demas claves', function (): void {
    // Un nombre de aplicacion no es un color: si la politica lo leyera como
    // tal, lanzaria al convertirlo.
    AccentContrastPolicy::assertAcceptable(
        false,
        SettingValue::of(SettingKey::BRANDING_APP_NAME, 'Hotel Marina'),
        SettingValue::of(SettingKey::ATTENDANCE_MAX_SHIFT_HOURS, 10),
    );

    expect(true)->toBeTrue();
})->group('RF-PD-08');

it('encuentra el acento aunque no sea la primera clave que cambia', function (): void {
    expect(fn () => AccentContrastPolicy::assertAcceptable(
        false,
        SettingValue::of(SettingKey::BRANDING_APP_NAME, 'Hotel Marina'),
        accentContrastValue('#ffe14d'),
    ))->toThrow(LowContrastAccentColor::class);
})->group('RF-PD-08');

it('no pide nada cuando no cambia ninguna clave', function (): void {
    AccentContrastPolicy::assertAcceptable(false);

    expect(true)->toBeTrue();
})->group('RF-PD-08');

it('dice que clave, que color, cuanto contrasta y cual es el minimo', function (): void {
    $exception = null;

    try {
        AccentContrastPolicy::assertAcceptable(false, accentContrastValue('#FFE14D'));
    } catch (LowContrastAccentColor $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(LowContrastAccentColor::class);
    assert($exception instanceof LowContrastAccentColor);

    expect($exception)->toBeInstanceOf(ProductDomainException::class)
        ->and($exception->key)->toBe(SettingKey::BRANDING_ACCENT_COLOR)
        // Normalizado, como lo publica la API.
        ->and($exception->color)->toBe('#ffe14d')
        ->and($exception->ratio)->toEqualWithDelta(1.226293, 0.000005)
        ->and($exception->minimum)->toBe(4.5)
        ->and(AccentContrastPolicy::MINIMUM)->toBe(4.5)
        ->and(LowContrastAccentColor::TRANSLATION_KEY)->toBe('settings.errors.low_contrast_accent')
        // El mensaje tecnico, para el log: en ingles y con las cifras.
        ->and($exception->getMessage())->toBe(
            'Setting "BRANDING_ACCENT_COLOR" colour #ffe14d reaches 1.23:1 against the light surfaces, below the 4.5:1 minimum, and was not confirmed.',
        );
})->group('RF-PD-08');

it('mide contra las dos superficies claras con texto y se queda con el peor contraste', function (string $color, float $peor): void {
    // La misma regla que `accentContrast()` de branding.ts: `surface` y
    // `surface-raised`. Un color oscuro contrasta menos con el fondo de pagina
    // (`#fff7ed`); el blanco, consigo mismo.
    expect(AccentContrastPolicy::worstContrast(HexColor::fromHex($color)))->toEqualWithDelta($peor, 0.000005);
})->with([
    'el acento de serie: peor sobre surface' => ['#b8542a', 4.557803],
    'el gris de referencia: peor sobre surface' => ['#767676', 4.278168],
    'blanco: peor sobre surface-raised' => ['#ffffff', 1.0],
    'el fondo de pagina: peor sobre si mismo' => ['#fff7ed', 1.0],
])->group('RF-PD-08');

it('declara las dos superficies claras del tema, en hexadecimal', function (): void {
    // Que coincidan con theme.css lo comprueba `AccentContrastSurfacesTest`.
    expect(AccentContrastPolicy::LIGHT_TEXT_SURFACES)->toBe(['#fff7ed', '#ffffff']);
})->group('RF-PD-08');
