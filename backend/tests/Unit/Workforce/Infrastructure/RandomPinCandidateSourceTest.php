<?php

declare(strict_types=1);

use App\Modules\Workforce\Infrastructure\Adapter\RandomPinCandidateSource;

/*
 * El azar de los PIN candidatos (RF-ID-09, H8).
 *
 * El generador ya no ve `random_int`: lo que se prueba aqui es que el adaptador
 * cubre el intervalo entero que se le pide, con los dos extremos, y nada fuera
 * de el. Un `random_int(1, $maximum)` dejaria fuera todos los PIN que empiezan
 * por ceros hasta el `000000`; uno hasta `$maximum - 1`, el ultimo.
 */

it('da los dos extremos del intervalo y nada fuera de el', function (): void {
    $source = new RandomPinCandidateSource;
    $seen = [];

    // Con un maximo de 3, que en 400 tiradas falte un valor tiene una
    // probabilidad del orden de 10^-50.
    for ($i = 0; $i < 400; $i++) {
        $seen[$source->pick(3)] = true;
    }

    ksort($seen);

    expect(array_keys($seen))->toBe([0, 1, 2, 3]);
})->group('RF-ID-09');

it('no repite el mismo candidato en todo el espacio de seis cifras', function (): void {
    // No demuestra aleatoriedad; detecta la fuente con estado o sembrada con
    // una constante, que devolveria siempre lo mismo.
    $source = new RandomPinCandidateSource;
    $picks = [];

    for ($i = 0; $i < 200; $i++) {
        $picks[] = $source->pick(999999);
    }

    expect(count(array_unique($picks)))->toBeGreaterThan(150);
})->group('RF-ID-09');
