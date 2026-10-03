<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorVocabulary;
use Tests\Support\Product\FrequentPersonNames;

/*
 * LAS PALABRAS TECNICAS EXCLUIDAS POR SER NOMBRES (ADR-048, RF-PD-15, RL-19).
 *
 * `ErrorVocabulary::NAME_CLASHES` deja escrito que palabras que el producto
 * emite se han sacado del vocabulario por coincidir con un nombre o apellido
 * frecuente. Esta prueba impide que vuelvan a entrar por descuido y que la
 * lista se use como cajon para tolerar palabras que no son nombres.
 */

it('no deja volver al vocabulario ninguna palabra excluida por ser un nombre', function (): void {
    $dentro = array_values(array_filter(ErrorVocabulary::nameClashes(), ErrorVocabulary::contains(...)));

    expect(ErrorVocabulary::nameClashes())->not->toBeEmpty()
        ->and($dentro)->toBe([], 'Estas palabras se excluyeron por ser nombres y han vuelto a ErrorVocabulary: '
            .implode(', ', $dentro));
})->group('RF-PD-15', 'RL-19');

it('solo excluye palabras que de verdad son nombres o apellidos del conjunto de datos', function (): void {
    $nombres = array_map(ErrorVocabulary::fold(...), FrequentPersonNames::words());
    $intrusas = array_values(array_diff(ErrorVocabulary::nameClashes(), $nombres));

    expect($intrusas)->toBe([], 'NAME_CLASHES no es una lista de tolerancias: '.implode(', ', $intrusas)
        .' no estan en el conjunto de nombres; si son tecnicas, van a WORDS.');
})->group('RF-PD-15');

it('guarda las exclusiones en la misma forma que el vocabulario y sin repetir', function (): void {
    $lista = ErrorVocabulary::nameClashes();

    expect(array_unique($lista))->toBe($lista)
        ->and(array_map(ErrorVocabulary::fold(...), $lista))->toBe($lista);
})->group('RF-PD-15');
