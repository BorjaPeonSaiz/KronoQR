<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\HexColor;

/*
 * El contraste WCAG 2.2 del acento de marca, en el servidor (MB2, MB3).
 *
 * Los valores de referencia son los PUBLICADOS —negro sobre blanco 21:1,
 * `#767676` 4,54:1— y los mismos que fija `packages/web-kit/tests/unit/
 * contrast.spec.ts`: si una de las dos copias de la formula cambia, una de las
 * dos suites lo nota. Los colores de los casos son los del registro de la
 * verificacion 2.1.0 (MB1, MB3): `#ffe14d`, `#fafafa`, `#808080` y `#ff0000`.
 */

it('lee #rrggbb y #rgb, con mayusculas o minusculas, y escribe siempre #rrggbb en minusculas', function (string $entrada, string $salida): void {
    expect(HexColor::fromHex($entrada)->toHex())->toBe($salida);
})->with([
    'seis digitos' => ['#b8542a', '#b8542a'],
    'en mayusculas' => ['#FFE14D', '#ffe14d'],
    'tres digitos' => ['#abc', '#aabbcc'],
    'con espacios alrededor' => ['  #0f5c8c ', '#0f5c8c'],
])->group('RF-PD-08');

it('separa los tres canales', function (): void {
    $color = HexColor::fromHex('#0f5c8c');

    expect($color->red)->toBe(15)
        ->and($color->green)->toBe(92)
        ->and($color->blue)->toBe(140);
})->group('RF-PD-08');

it('rechaza lo que no es un color hexadecimal', function (string $entrada): void {
    expect(fn (): HexColor => HexColor::fromHex($entrada))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'una palabra' => ['azul'],
    'sin almohadilla' => ['0f5c8c'],
    'cuatro digitos' => ['#abcd'],
    'ocho digitos con alfa' => ['#0f5c8cff'],
    'un digito que no es hexadecimal' => ['#0f5c8g'],
    'vacio' => [''],
    'notacion rgb()' => ['rgb(17,24,39)'],
])->group('RF-PD-08');

it('mide la luminancia relativa de la recomendacion, a los dos lados del umbral de linealizacion', function (string $color, float $luminancia): void {
    expect(HexColor::fromHex($color)->relativeLuminance())->toEqualWithDelta($luminancia, 1e-9);
})->with([
    'negro' => ['#000000', 0.0],
    'blanco' => ['#ffffff', 1.0],
    // 10/255 = 0,0392, por debajo de 0,04045: tramo lineal (c / 12,92).
    'canal 10, tramo lineal' => ['#0a0a0a', 0.0030352698],
    // 11/255 = 0,0431, por encima: tramo potencial.
    'canal 11, tramo potencial' => ['#0b0b0b', 0.0033465358],
    // Pesos distintos por canal: el verde pesa diez veces mas que el azul.
    'rojo puro' => ['#ff0000', 0.2126],
    'verde puro' => ['#00ff00', 0.7152],
    'azul puro' => ['#0000ff', 0.0722],
    'el primary-strong del producto' => ['#b8542a', 0.16698162],
])->group('RF-PD-08');

it('mide el contraste con los valores publicados', function (string $primerPlano, string $fondo, float $contraste): void {
    expect(HexColor::fromHex($primerPlano)->contrastWith(HexColor::fromHex($fondo)))
        ->toEqualWithDelta($contraste, 0.000005);
})->with([
    'negro sobre blanco' => ['#000000', '#ffffff', 21.0],
    'un color consigo mismo' => ['#808080', '#808080', 1.0],
    // El gris mas claro que pasa AA sobre blanco, el de la referencia de WCAG.
    '#767676 sobre blanco' => ['#767676', '#ffffff', 4.542225],
    // Doc 06 §2: el valor de serie del catalogo, 4,84:1.
    'el acento de serie sobre blanco' => ['#b8542a', '#ffffff', 4.839120],
    // Los del registro MB3 y MB1.
    'amarillo palido sobre blanco' => ['#ffe14d', '#ffffff', 1.301982],
    'casi blanco sobre blanco' => ['#fafafa', '#ffffff', 1.043765],
    'gris medio sobre blanco' => ['#808080', '#ffffff', 3.949440],
    'rojo puro sobre blanco' => ['#ff0000', '#ffffff', 3.998477],
])->group('RF-PD-08');

it('da el mismo contraste en los dos sentidos', function (): void {
    $acento = HexColor::fromHex('#ffe14d');
    $blanco = HexColor::white();

    expect($acento->contrastWith($blanco))->toBe($blanco->contrastWith($acento));
})->group('RF-PD-08');

it('cumple el minimo justo en el minimo, y no por debajo', function (): void {
    $gris = HexColor::fromHex('#767676');
    $exacto = $gris->contrastWith(HexColor::white());

    expect($gris->meetsContrast(HexColor::white(), $exacto))->toBeTrue()
        ->and($gris->meetsContrast(HexColor::white(), $exacto + 0.000001))->toBeFalse()
        ->and($gris->meetsContrast(HexColor::white(), HexColor::WCAG_TEXT_MINIMUM))->toBeTrue()
        ->and(HexColor::fromHex('#777777')->meetsContrast(HexColor::white(), HexColor::WCAG_TEXT_MINIMUM))->toBeFalse();
})->group('RF-PD-08');

it('publica los dos minimos de WCAG 2.2 AA que usa el producto', function (): void {
    // Los mismos que `WCAG_AA_MINIMUM` de `contrast.ts`.
    expect(HexColor::WCAG_TEXT_MINIMUM)->toBe(4.5)
        ->and(HexColor::WCAG_LARGE_MINIMUM)->toBe(3.0);
})->group('RF-PD-08');

it('no toca un color que ya llega al minimo', function (string $color): void {
    $original = HexColor::fromHex($color);

    expect($original->darkenedUntil(HexColor::white(), HexColor::WCAG_TEXT_MINIMUM)->toHex())->toBe($color);
})->with([
    'el acento de serie' => ['#b8542a'],
    'un azul oscuro' => ['#0f5c8c'],
    'justo en el minimo' => ['#767676'],
    'negro' => ['#000000'],
])->group('RF-PD-08');

it('oscurece hasta el minimo los colores reales del registro, y ni un paso mas', function (string $color, string $oscurecido): void {
    $blanco = HexColor::white();
    $resultado = HexColor::fromHex($color)->darkenedUntil($blanco, HexColor::WCAG_TEXT_MINIMUM);

    expect($resultado->toHex())->toBe($oscurecido)
        // El color que se imprime llega, ya redondeado a enteros.
        ->and($resultado->contrastWith($blanco))->toBeGreaterThanOrEqual(HexColor::WCAG_TEXT_MINIMUM);
})->with([
    'amarillo palido (MB3: 1,30:1)' => ['#ffe14d', '#867628'],
    'casi blanco' => ['#fafafa', '#707070'],
    'gris medio' => ['#808080', '#767676'],
    'rojo puro' => ['#ff0000', '#ec0000'],
    'el terracota claro del producto' => ['#d66c3a', '#b65c31'],
    'blanco' => ['#ffffff', '#737373'],
    'un pelo por debajo del minimo' => ['#777777', '#747474'],
])->group('RF-PD-08');

it('para en el primer paso que llega: el anterior todavia no llegaba', function (): void {
    // 40 pasos de un 2,5 % hacia el negro, como `adjustUntil()` de branding.ts.
    // `#ffe14d` llega en el paso 19 (#867628); el 18 se queda corto.
    $blanco = HexColor::white();
    $pasoAnterior = HexColor::fromHex('#8c7c2a');

    expect($pasoAnterior->contrastWith($blanco))->toBeLessThan(HexColor::WCAG_TEXT_MINIMUM)
        ->and(HexColor::fromHex('#ffe14d')->darkenedUntil($blanco, HexColor::WCAG_TEXT_MINIMUM)->toHex())->toBe('#867628');
})->group('RF-PD-08');

it('acepta otro minimo y otro fondo', function (): void {
    // El minimo de componente (3:1) se alcanza antes que el de texto.
    expect(HexColor::fromHex('#ffe14d')->darkenedUntil(HexColor::white(), HexColor::WCAG_LARGE_MINIMUM)->toHex())
        ->toBe('#a69232');
})->group('RF-PD-08');

it('devuelve el negro cuando ni el negro llega', function (): void {
    // Negro sobre el gris #777777 da 4,69:1: ningun tono hacia el negro llega a
    // 7:1, y lo mas lejos que se puede ir es el propio negro, no un bucle sin
    // salida ni el color de partida.
    expect(HexColor::fromHex('#ffe14d')->darkenedUntil(HexColor::fromHex('#777777'), 7.0)->toHex())
        ->toBe('#000000');
})->group('RF-PD-08');

it('construye el blanco y el negro', function (): void {
    expect(HexColor::white()->toHex())->toBe('#ffffff')
        ->and(HexColor::black()->toHex())->toBe('#000000');
})->group('RF-PD-08');
