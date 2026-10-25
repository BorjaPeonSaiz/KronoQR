<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Adapter\RandomTemporaryPasswordGenerator;

/*
 * La contrasena temporal cumple la politica de RF-ID-01 POR CONSTRUCCION
 * (RF-ID-10): trescientas generaciones por longitud y ninguna excepcion. Sin
 * contenedor: el generador solo depende de `random_int`.
 */

it('genera siempre las cuatro clases, sin caracteres ambiguos y con la longitud mayor entre 20 y el minimo', function (
    int $minLength,
    int $expectedLength,
): void {
    $generator = new RandomTemporaryPasswordGenerator;

    // Trescientas por longitud y no mil, y comprobadas con PHP con una sola
    // afirmacion al final: mil con siete `expect` cada una eran 21.000
    // afirmaciones y la segunda prueba mas cara de la suite unitaria (doc 02
    // §9.2, presupuesto de 5 s). Cada contrasena se sigue comprobando entera, y
    // las que fallen salen en el mensaje.
    $rotas = [];

    for ($i = 0; $i < 300; $i++) {
        $password = $generator->generate($minLength);

        $cumple = strlen($password) === $expectedLength
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[^a-zA-Z0-9]/', $password) === 1
            && preg_match('/[lIO01]/', $password) === 0
            // ASCII: nunca pasa de los 72 bytes que lee `bcrypt`.
            && mb_check_encoding($password, 'ASCII');

        if (! $cumple) {
            $rotas[] = $password;
        }
    }

    expect($rotas)->toBe([]);
})->with([
    'minimo de serie, 12' => [12, 20],
    'minimo mayor que 20' => [30, 30],
    'minimo por encima de 72, recortado' => [100, 72],
])->group('RF-ID-10', 'RF-ID-01');

it('no repite contrasena entre dos generaciones', function (): void {
    $generator = new RandomTemporaryPasswordGenerator;

    expect($generator->generate(12))->not->toBe($generator->generate(12));
})->group('RF-ID-10');
