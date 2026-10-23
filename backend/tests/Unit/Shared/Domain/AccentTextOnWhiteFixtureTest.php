<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\Branding;
use App\Modules\Shared\Domain\ValueObject\HexColor;

/*
 * Los casos COMPARTIDOS del acento para texto sobre papel blanco (V1-UX-1, MB3).
 *
 * El backend es el unico sitio de verdad para el papel: la tarjeta, la hoja de
 * instrucciones y los tres PDF sellados pintan `Branding::accentForTextOnWhite()`
 * y ninguna plantilla calcula un color. El panel oscurece el mismo acento en
 * pantalla con `adjustUntil()` de `packages/web-kit/src/branding.ts`, y los dos
 * lados tienen que dar el mismo tono para el mismo color, o el hotel veria en
 * «Marca» un color y en la tarjeta otro.
 *
 * Por eso los casos no viven en esta prueba sino en
 * `tests/Fixtures/accent-text-on-white.json`, un fichero de datos que puede leer
 * tambien la prueba unitaria de web-kit. Aqui se fija que el dominio los cumple
 * todos, que cada resultado llega de verdad al minimo YA REDONDEADO, y que los
 * parametros del fichero son los del dominio.
 */

const ACCENT_TEXT_ON_WHITE_FIXTURE = __DIR__.'/../../../Fixtures/accent-text-on-white.json';

/**
 * @return array{background: string, minimum: float, steps: int, cases: list<array{label: string, accent: string, text: string}>}
 */
function accentTextOnWhiteFixture(): array
{
    $raw = file_get_contents(ACCENT_TEXT_ON_WHITE_FIXTURE);
    expect($raw)->toBeString();

    /** @var array{background: string, minimum: float, steps: int, cases: list<array{label: string, accent: string, text: string}>} $data */
    $data = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);

    return $data;
}

it('usa el fondo, el minimo y los pasos del dominio', function (): void {
    $fixture = accentTextOnWhiteFixture();

    expect($fixture['background'])->toBe(HexColor::white()->toHex())
        ->and((float) $fixture['minimum'])->toBe(HexColor::WCAG_TEXT_MINIMUM)
        ->and($fixture['steps'])->toBe(40)
        ->and($fixture['cases'])->not->toBeEmpty();
})->group('RF-PD-08');

it('da para cada acento compartido el mismo tono que se imprime', function (): void {
    foreach (accentTextOnWhiteFixture()['cases'] as $case) {
        $printed = (new Branding('Hotel Marina', null, $case['accent']))->accentForTextOnWhite();

        expect($printed)->toBe($case['text'], $case['label'])
            ->and(HexColor::fromHex($printed)->contrastWith(HexColor::white()))
            ->toBeGreaterThanOrEqual(HexColor::WCAG_TEXT_MINIMUM);
    }
})->group('RF-PD-08');
