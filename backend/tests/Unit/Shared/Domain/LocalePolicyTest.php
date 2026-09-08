<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\LocalePolicy;

/*
 * Los idiomas de la instalacion (RF-PD-01, RF-PD-08, tarea 5.8).
 *
 * LO QUE SE PRUEBA AQUI ES UNA INVARIANTE, no un formato: el idioma por defecto
 * esta siempre entre los disponibles. Sin ella, un `PATCH` que quitara `es` de la
 * lista sin tocar el idioma por defecto dejaria la instalacion respondiendo en un
 * idioma que su propio selector no ofrece — el quiosco enseñaria dos banderas y
 * la API contestaria en una tercera.
 */

it('guarda el idioma por defecto y la lista que se ofrece', function (): void {
    $policy = new LocalePolicy('es', ['es', 'en']);

    expect($policy->default)->toBe('es')
        ->and($policy->available)->toBe(['es', 'en']);
})->group('RF-PD-08', 'RF-PD-01');

it('no admite una instalacion sin ningun idioma', function (): void {
    // Una instalacion sin idiomas no puede responder nada. El catalogo lo
    // impide, pero este objeto se construye tambien desde el respaldo de
    // configuracion, que si podria venir vacio.
    expect(fn (): LocalePolicy => new LocalePolicy('es', []))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-PD-08', 'RF-PD-01');

it('no admite un idioma por defecto que no se ofrece', function (): void {
    expect(fn (): LocalePolicy => new LocalePolicy('fr', ['es', 'en']))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-PD-08', 'RF-PD-01');

it('dice cual es el idioma huerfano y cuales habia, porque el mensaje lo lee una persona', function (): void {
    // Este texto acaba en un log de la instalacion del cliente, y quien lo lee no
    // tiene el codigo delante: sin los dos datos, «configuracion invalida» obliga
    // a adivinar cual de las dos claves esta mal.
    expect(fn (): LocalePolicy => new LocalePolicy('fr', ['es', 'en']))
        ->toThrow(InvalidArgumentException::class, 'fr');
})->group('RF-PD-08', 'RF-PD-01');

it('admite una instalacion con un solo idioma', function (): void {
    // El caso mas comun de un hotel que trabaja solo en castellano, y el ejemplo
    // «idiomas» del contrato.
    $policy = new LocalePolicy('es', ['es']);

    expect($policy->available)->toBe(['es']);
})->group('RF-PD-08', 'RF-PD-01');
