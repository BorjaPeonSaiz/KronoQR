<?php

declare(strict_types=1);

use App\Modules\Workforce\Infrastructure\Adapter\RandomEmployeeCodeGenerator;

/*
 * El azar del codigo de empleado (RF-GP-01, RF-ID-06, H8).
 *
 * El codigo va impreso en la tarjeta y es la mitad publica de la credencial del
 * portal: uno secuencial revelaria cuanta gente trabaja en el hotel y
 * permitiria adivinar codigos ajenos contando. El dominio decide la forma; este
 * adaptador, el azar.
 */

it('genera codigos distintos en cada llamada, con la forma canonica', function (): void {
    $generator = new RandomEmployeeCodeGenerator;
    $codes = [];

    for ($i = 0; $i < 200; $i++) {
        $codes[] = $generator->next()->value;
    }

    expect(array_unique($codes))->toHaveCount(200);

    foreach ($codes as $code) {
        expect($code)->toMatch('/^E[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{9}$/');
    }
})->group('RF-GP-01', 'RF-ID-06');

it('no genera codigos correlativos', function (): void {
    // Si alguien sustituyera el azar por un contador, dos codigos consecutivos
    // compartirian casi todas las posiciones y esto lo delataria.
    $generator = new RandomEmployeeCodeGenerator;
    $first = $generator->next()->value;
    $second = $generator->next()->value;
    $shared = 0;

    for ($i = 1; $i < mb_strlen($first); $i++) {
        $shared += mb_substr($first, $i, 1) === mb_substr($second, $i, 1) ? 1 : 0;
    }

    // Con 31 simbolos por posicion, compartir mas de la mitad es practicamente
    // imposible por azar.
    expect($shared)->toBeLessThan(5);
})->group('RF-GP-01', 'RF-ID-06');

it('usa todo el alfabeto, de la primera posicion a la ultima', function (): void {
    // Un `random_int(0, 29)` dejaria fuera el ultimo caracter sin que nada mas
    // fallara. 2000 codigos son 18 000 caracteres: que falte uno de 31 por azar
    // tiene una probabilidad del orden de 10^-250.
    $generator = new RandomEmployeeCodeGenerator;
    $seen = [];

    for ($i = 0; $i < 2000; $i++) {
        foreach (mb_str_split(mb_substr($generator->next()->value, 1)) as $character) {
            $seen[$character] = true;
        }
    }

    expect($seen)->toHaveCount(31);
})->group('RF-ID-06');
