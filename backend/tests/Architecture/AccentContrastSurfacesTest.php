<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Policy\AccentContrastPolicy;
use Tests\Architecture\Support\Repo;

/*
 * El servidor y el panel miden el acento contra las MISMAS superficies (MB2).
 *
 * `AccentContrastPolicy` decide si un `PATCH /settings` necesita
 * `confirm_low_contrast`; `accentContrast()` de `packages/web-kit/src/branding.ts`
 * decide si la pantalla «Marca» enseña la casilla de confirmacion. Los dos miden
 * contra `--kq-color-surface` y `--kq-color-surface-raised`, y el dominio no lee
 * CSS: tiene los dos valores copiados. Esta prueba es la que impide que el dia
 * que alguien retoque el fondo de pagina en `theme.css` el panel pida confirmar
 * un color que el servidor acepta sin ella, o al reves.
 */

const ACCENT_CONTRAST_SURFACES_THEME = 'packages/web-kit/src/theme.css';

function accentContrastThemeToken(string $token): ?string
{
    $css = Repo::contents(ACCENT_CONTRAST_SURFACES_THEME);

    if (preg_match('/'.preg_quote($token, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $match) !== 1) {
        return null;
    }

    return strtolower($match[1]);
}

it('copia en el dominio las dos superficies claras de theme.css', function (): void {
    expect(AccentContrastPolicy::LIGHT_TEXT_SURFACES)->toBe([
        accentContrastThemeToken('--kq-color-surface'),
        accentContrastThemeToken('--kq-color-surface-raised'),
    ]);
})->group('RF-PD-08');

it('mide en el panel contra esas mismas dos superficies', function (): void {
    $branding = str_replace("\r\n", "\n", Repo::contents('packages/web-kit/src/branding.ts'));

    expect($branding)->toContain("['--kq-color-surface', '--kq-color-surface-raised']")
        ->and($branding)->toContain('export function accentContrast(');
})->group('RF-PD-08');
