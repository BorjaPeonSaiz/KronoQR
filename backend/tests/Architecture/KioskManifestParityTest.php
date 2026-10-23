<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\GetKioskManifestHandler;
use Tests\Architecture\Support\Repo;

/*
 * El manifiesto dinamico del quiosco y el estatico de reserva dicen lo mismo (PR7).
 *
 * `frontend-kiosk/vite.config.ts` genera el manifiesto estatico (reserva sin
 * enlazar) y el backend sirve el de la marca (`KioskManifestResource` y
 * `GetKioskManifestHandler`). Lo que NO depende de la marca -color de fondo,
 * pantalla completa, orientacion e iconos de serie- esta escrito en los dos
 * sitios, porque ni el backend lee el build ni el build llama al backend. Sin
 * esta prueba, el dia que alguien cambie el icono o la orientacion en uno,
 * la tablet se instalaria distinta segun contestara o no el servidor.
 *
 * Se lee el TEXTO de `vite.config.ts` y no se importa: es un fichero de
 * configuracion de Node que PHP no puede ejecutar, y una expresion regular
 * acotada falla en voz alta si el formato cambia (cada helper lanza si no
 * encuentra lo que busca).
 */

/** El bloque `manifest: { ... icons: [ ... ] }` del plugin PWA, sin comentarios. */
function kioskManifestParityViteManifest(): string
{
    $vite = Repo::contents('frontend-kiosk/vite.config.ts');

    if (preg_match('/manifest:\s*\{(.*?)\n      \},\n      workbox:/s', $vite, $match) !== 1) {
        throw new RuntimeException('No se encuentra el bloque `manifest` de VitePWA en frontend-kiosk/vite.config.ts.');
    }

    return (string) preg_replace('~^\s*//.*$~m', '', $match[1]);
}

function kioskManifestParityViteString(string $key): string
{
    if (preg_match('/\b'.preg_quote($key, '/')."\\s*:\\s*'([^']*)'/", kioskManifestParityViteManifest(), $match) !== 1) {
        throw new RuntimeException("No se encuentra `{$key}` en el manifiesto de vite.config.ts.");
    }

    return $match[1];
}

function kioskManifestParityPhpConstant(string $file, string $name): string
{
    $php = Repo::contents($file);

    if (preg_match('/const\s+(?:string\s+)?'.preg_quote($name, '/')."\\s*=\\s*'([^']*)'/", $php, $match) !== 1) {
        throw new RuntimeException("No se encuentra la constante {$name} en {$file}.");
    }

    return $match[1];
}

const KIOSK_MANIFEST_PARITY_RESOURCE = 'backend/app/Modules/Product/Http/Resource/KioskManifestResource.php';
const KIOSK_MANIFEST_PARITY_HANDLER = 'backend/app/Modules/Product/Application/UseCase/GetKioskManifestHandler.php';

it('sirve el mismo color de fondo y el de serie que el manifiesto estatico', function (): void {
    $vite = kioskManifestParityViteString('background_color');

    expect(kioskManifestParityPhpConstant(KIOSK_MANIFEST_PARITY_RESOURCE, 'BACKGROUND_COLOR'))->toBe($vite)
        ->and(kioskManifestParityPhpConstant(KIOSK_MANIFEST_PARITY_HANDLER, 'PRODUCT_THEME_COLOR'))->toBe(kioskManifestParityViteString('theme_color'))
        ->and($vite)->toBe(kioskManifestParityViteString('theme_color'));
})->group('RF-PD-08');

it('usa como color de fondo y de tema el token del fondo del quiosco', function (): void {
    $theme = Repo::contents('packages/web-kit/src/theme.css');

    expect(preg_match('/--kq-color-kiosk-surface:\s*(#[0-9a-fA-F]{6})\s*;/', $theme, $token))->toBe(1)
        ->and(strtolower($token[1] ?? ''))->toBe(kioskManifestParityPhpConstant(KIOSK_MANIFEST_PARITY_RESOURCE, 'BACKGROUND_COLOR'))
        ->and(strtolower($token[1] ?? ''))->toBe(kioskManifestParityPhpConstant(KIOSK_MANIFEST_PARITY_HANDLER, 'PRODUCT_THEME_COLOR'))
        ->and(strtolower($token[1] ?? ''))->toBe(kioskManifestParityViteString('background_color'));
})->group('RF-PD-08');

it('declara el mismo display y ninguno fija la orientacion que el manifiesto estatico', function (): void {
    $resource = Repo::contents(KIOSK_MANIFEST_PARITY_RESOURCE);

    foreach (['display'] as $key) {
        expect(preg_match("/'{$key}'\\s*=>\\s*'([^']*)'/", $resource, $match))->toBe(1)
            ->and($match[1] ?? null)->toBe(kioskManifestParityViteString($key));
    }

    // Sin orientacion en ninguno de los dos: la tablet se monta tambien en vertical (V6-KI-03).
    expect($resource)->not->toContain("'orientation'")
        ->and(kioskManifestParityViteManifest())->not->toContain('orientation:');

    expect(preg_match("/'display_override'\\s*=>\\s*\\[([^\\]]*)\\]/", $resource, $php))->toBe(1)
        ->and(preg_match('/display_override:\\s*\\[([^\\]]*)\\]/', kioskManifestParityViteManifest(), $vite))->toBe(1)
        ->and(preg_split('/\s*,\s*/', trim($php[1] ?? '', " ,\n")))->toBe(preg_split('/\s*,\s*/', trim($vite[1] ?? '', " ,\n")));
})->group('RF-PD-08');

it('publica como iconos de serie los mismos que genera el manifiesto estatico', function (): void {
    preg_match_all(
        "/src:\\s*'([^']+)',\\s*sizes:\\s*'([^']+)',\\s*type:\\s*'image\\/png',\\s*purpose:\\s*'([^']+)'/",
        kioskManifestParityViteManifest(),
        $matches,
        PREG_SET_ORDER,
    );

    $vite = array_map(static fn (array $m): array => [$m[1], $m[2], $m[3]], $matches);

    $constants = (new ReflectionClass(GetKioskManifestHandler::class))->getReflectionConstants();
    $php = null;

    foreach ($constants as $constant) {
        if ($constant->getName() === 'PRODUCT_ICONS') {
            $php = $constant->getValue();
        }
    }

    expect($vite)->not->toBeEmpty()
        ->and($php)->toBe($vite);
})->group('RF-PD-08');

it('cuelga el manifiesto de la misma ruta en la que Nginx monta el quiosco', function (): void {
    // `id`, `start_url` y `scope` van en el borde HTTP del backend y los iconos de
    // serie, relativos, se resuelven contra esa ruta: la misma que el Dockerfile de
    // Nginx pasa a `vite build` como base del quiosco.
    $dockerfile = Repo::contents('infra/docker/nginx/Dockerfile');

    expect(preg_match('~frontend-kiosk && [^\n]*KRONOQR_BASE=(\S+)~', $dockerfile, $base))->toBe(1)
        ->and(kioskManifestParityPhpConstant(KIOSK_MANIFEST_PARITY_RESOURCE, 'KIOSK_PATH'))->toBe($base[1] ?? null);
})->group('RF-PD-08');

it('apunta el index.html del quiosco al manifiesto del servidor y no al estatico', function (): void {
    $html = Repo::contents('frontend-kiosk/index.html');

    expect(preg_match_all('/<link rel="manifest"/', $html))->toBe(1)
        ->and($html)->toContain('href="/api/v1/branding/manifest.webmanifest"');
})->group('RF-PD-08');
