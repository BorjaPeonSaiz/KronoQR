<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\Branding;

/*
 * La marca de la instalacion como objeto de valor (RF-PD-01).
 *
 * **No cubre RF-PD-08 y no lleva su etiqueta.** Aquel requisito pide la marca
 * APLICADA al quiosco, al panel, al portal y a los PDF, y eso lo cumple la
 * tarea 5.8. Aqui solo se comprueba el objeto de valor por el que llegara.
 *
 * Cruza la frontera entre modulos —la consumen Identity, Compliance y las tres
 * SPA via Product— y por eso valida al construirse: quien la recibe dibuja con
 * ella, y un color mal escrito no puede convertirse en una tarjeta impresa sin
 * estilo o en un PDF sellado ilegible.
 */

it('conserva nombre, logotipo y color de acento', function (): void {
    $branding = new Branding('Hotel de prueba', '/srv/kronoqr/logo.png', '#0f172a');

    expect($branding->applicationName)->toBe('Hotel de prueba')
        ->and($branding->logoPath)->toBe('/srv/kronoqr/logo.png')
        ->and($branding->accentColor)->toBe('#0f172a');
})->group('RF-PD-01');

it('admite una instalacion sin logotipo', function (): void {
    // Sin logotipo se imprime perfectamente; lo que no puede es fallar por que
    // falte una imagen (regla dura 19, y el criterio de config/branding.php).
    $branding = new Branding('KronoQR', null, '#111827');

    expect($branding->logoPath)->toBeNull();
})->group('RF-PD-01');

it('rechaza una marca que no se puede dibujar', function (string $name, ?string $logo, string $accent): void {
    expect(fn (): Branding => new Branding($name, $logo, $accent))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin nombre de aplicacion' => ['', null, '#111827'],
    'con un nombre en blanco' => ['   ', null, '#111827'],
    // Una ruta en blanco no es «sin logotipo»: es una ruta mal construida.
    'con la ruta del logotipo en blanco' => ['KronoQR', ' ', '#111827'],
    'con un color que no es notacion CSS' => ['KronoQR', null, 'azul'],
    'con un color de tres digitos' => ['KronoQR', null, '#fff'],
    'con un color sin almohadilla' => ['KronoQR', null, '111827'],
])->group('RF-PD-01');
