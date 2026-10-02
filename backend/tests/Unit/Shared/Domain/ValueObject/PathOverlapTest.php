<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\PathOverlap;

/*
 * ¿Dos raices de clase se pisan? (ADR-045, condicion C3). Es lo que hace fallar
 * a `product:doctor` y a la prueba de inventario cuando dos clases comparten
 * directorio.
 */

it('normaliza separadores, puntos y barras repetidas', function (string $ruta, string $esperada): void {
    expect(PathOverlap::normalise($ruta))->toBe($esperada);
})->with([
    'barra final' => ['/var/www/html/storage/app/', '/var/www/html/storage/app'],
    'barras repetidas' => ['/var//www///html', '/var/www/html'],
    'punto' => ['/var/./www', '/var/www'],
    'dos puntos' => ['/var/www/html/storage/app/../app/exports', '/var/www/html/storage/app/exports'],
    'contrabarras' => ['\\srv\\exports', '/srv/exports'],
    'raiz' => ['/', '/'],
    'relativa' => ['storage/app/../app', 'storage/app'],
])->group('RF-PD-13');

it('dos rutas iguales se solapan', function (): void {
    expect(PathOverlap::between('/srv/app/exports', '/srv/app/exports/'))->toBeTrue();
})->group('RF-PD-13');

it('una dentro de otra se solapan, en los dos ordenes', function (): void {
    expect(PathOverlap::between('/srv/app', '/srv/app/exports'))->toBeTrue()
        ->and(PathOverlap::between('/srv/app/exports', '/srv/app'))->toBeTrue()
        ->and(PathOverlap::contains('/srv/app', '/srv/app/exports'))->toBeTrue()
        ->and(PathOverlap::contains('/srv/app/exports', '/srv/app'))->toBeFalse();
})->group('RF-PD-13');

it('compara por segmentos completos, no por prefijo de texto', function (): void {
    // `exports-old` no esta dentro de `exports`.
    expect(PathOverlap::between('/srv/app/exports', '/srv/app/exports-old'))->toBeFalse()
        ->and(PathOverlap::between('/srv/app/reports', '/srv/app/exports'))->toBeFalse();
})->group('RF-PD-13');

it('la raiz del sistema contiene a todas', function (): void {
    expect(PathOverlap::contains('/', '/srv/app'))->toBeTrue()
        ->and(PathOverlap::contains('/srv', '/'))->toBeFalse();
})->group('RF-PD-13');
