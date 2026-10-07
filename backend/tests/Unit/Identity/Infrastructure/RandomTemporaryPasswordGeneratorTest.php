<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Adapter\RandomTemporaryPasswordGenerator;

/*
 * La contrasena temporal cumple la politica de RF-ID-01 POR CONSTRUCCION
 * (RF-ID-10): mil generaciones y ninguna excepcion. Sin contenedor: el
 * generador solo depende de `random_int`.
 */

it('genera siempre las cuatro clases, sin caracteres ambiguos y con la longitud mayor entre 20 y el minimo', function (
    int $minLength,
    int $expectedLength,
): void {
    $generator = new RandomTemporaryPasswordGenerator;

    for ($i = 0; $i < 1000; $i++) {
        $password = $generator->generate($minLength);

        expect(strlen($password))->toBe($expectedLength)
            ->and($password)->toMatch('/[a-z]/')
            ->and($password)->toMatch('/[A-Z]/')
            ->and($password)->toMatch('/[0-9]/')
            ->and($password)->toMatch('/[^a-zA-Z0-9]/')
            ->and($password)->not->toMatch('/[lIO01]/')
            // ASCII: nunca pasa de los 72 bytes que lee `bcrypt`.
            ->and(mb_check_encoding($password, 'ASCII'))->toBeTrue();
    }
})->with([
    'minimo de serie, 12' => [12, 20],
    'minimo mayor que 20' => [30, 30],
    'minimo por encima de 72, recortado' => [100, 72],
])->group('RF-ID-10', 'RF-ID-01');

it('no repite contrasena entre dos generaciones', function (): void {
    $generator = new RandomTemporaryPasswordGenerator;

    expect($generator->generate(12))->not->toBe($generator->generate(12));
})->group('RF-ID-10');
