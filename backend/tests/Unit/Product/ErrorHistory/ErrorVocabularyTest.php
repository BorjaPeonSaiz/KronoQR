<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorVocabulary;
use Tests\Support\Product\FrequentPersonNames;
use Tests\Support\Product\FrequentPersonNameSections;

/*
 * EL VOCABULARIO TECNICO CERRADO (ADR-048, RF-PD-15, RL-19).
 *
 * Una palabra solo sobrevive en `error_events` si esta aqui. Por eso lo que mas
 * importa de esta lista es lo que NO tiene: ningun nombre ni apellido
 * frecuente. El conjunto de datos vive en `tests/Support/Product/` y crece sin
 * tocar esta prueba (H5). Sin excepciones: si choca, la palabra sale del
 * vocabulario.
 */

it('no comparte ninguna palabra con los nombres y apellidos frecuentes', function (): void {
    $nombres = FrequentPersonNames::words();
    $choques = array_values(array_filter($nombres, ErrorVocabulary::contains(...)));

    // El conjunto no puede encoger: una lista corta pasaria siempre. 4 133
    // palabras distintas el 03-10-2026 (H5).
    expect(count($nombres))->toBeGreaterThan(4100)
        ->and($choques)->toBe([], count($choques).' palabra(s) del vocabulario son nombres o apellidos frecuentes y '
            .'tienen que salir de ErrorVocabulary (ADR-048, sin excepciones): '.implode(', ', $choques));
})->group('RF-PD-15', 'RL-19');

it('vigila al menos mil palabras distintas de cada lista de Espana', function (string $seccion): void {
    // H5: mil nombres por sexo y mil apellidos (INE). Con menos, la disjuncion
    // de arriba demostraria menos de lo que promete la guia.
    expect(count(FrequentPersonNameSections::words($seccion)))->toBeGreaterThanOrEqual(1000);
})->with([
    'nombres de hombre' => ['Espana: nombres de hombre'],
    'nombres de mujer' => ['Espana: nombres de mujer'],
    'apellidos' => ['Espana: apellidos'],
])->group('RF-PD-15', 'RL-19');

it('vigila las nacionalidades habituales en hosteleria que pide el dictamen', function (string $seccion): void {
    expect(FrequentPersonNameSections::words($seccion))->not->toBeEmpty();
})->with([
    'Rumania' => ['Rumania'],
    'Marruecos' => ['Marruecos'],
    'Colombia, Venezuela, Ecuador y Peru' => ['Colombia, Venezuela, Ecuador y Peru'],
    'Ucrania' => ['Ucrania'],
    'Filipinas' => ['Filipinas'],
    'Reino Unido' => ['Reino Unido'],
    'Italia' => ['Italia'],
    'Portugal' => ['Portugal'],
    'China' => ['China (romanizados)'],
])->group('RF-PD-15', 'RL-19');

it('esta ordenada byte a byte y sin repetidos, que es lo que permite buscar por biseccion', function (): void {
    $palabras = ErrorVocabulary::words();
    $ordenadas = $palabras;
    usort($ordenadas, strcmp(...));

    expect($palabras)->toBe($ordenadas)
        ->and(array_unique($palabras))->toBe($palabras)
        ->and(count($palabras))->toBeGreaterThan(1000);
})->group('RF-PD-15', 'RL-19');

it('guarda cada palabra en minusculas, sin tildes y con al menos dos letras', function (): void {
    $malas = array_values(array_filter(
        ErrorVocabulary::words(),
        static fn (string $palabra): bool => preg_match('/^[a-z0-9]+$/', $palabra) !== 1
            || preg_match_all('/[a-z]/', $palabra) < 2
            || ErrorVocabulary::fold($palabra) !== $palabra,
    ));

    expect($malas)->toBe([]);
})->group('RF-PD-15', 'RL-19');

it('encuentra cada una de sus palabras', function (): void {
    // Recorre la lista entera: un fallo de la biseccion en cualquier punto
    // —el primero, el ultimo, el del medio— se ve aqui.
    $perdidas = array_values(array_filter(
        ErrorVocabulary::words(),
        static fn (string $palabra): bool => ! ErrorVocabulary::contains($palabra),
    ));

    expect($perdidas)->toBe([]);
})->group('RF-PD-15');

it('no encuentra lo que no esta, tampoco antes de la primera ni despues de la ultima', function (string $palabra): void {
    expect(ErrorVocabulary::contains($palabra))->toBeFalse();
})->with([
    'antes de la primera' => ['aaaa'],
    'despues de la ultima' => ['zzzzzz'],
    'entre dos' => ['employeez'],
    'prefijo de una que si esta' => ['employe'],
    'vacia' => [''],
    'un apellido' => ['ficticiana'],
])->group('RF-PD-15', 'RL-19');

it('compara sin distinguir mayusculas, tildes ni marcas combinantes', function (string $palabra): void {
    expect(ErrorVocabulary::contains($palabra))->toBeTrue();
})->with([
    'mayusculas' => ['EMPLOYEE'],
    'capitalizada' => ['Employee'],
    'con tilde' => ['código'],
    'con tilde en mayusculas' => ['CÓDIGO'],
    'con la tilde como marca combinante' => ["co\u{0301}digo"],
    'con cifras' => ['SHA256'],
])->group('RF-PD-15');

it('pliega las letras latinas a su forma sin tilde', function (string $entrada, string $esperado): void {
    expect(ErrorVocabulary::fold($entrada))->toBe($esperado);
})->with([
    'castellano' => ['ÁÉÍÓÚÜÑ', 'aeiouun'],
    'catalan y portugues' => ['àèòçãõ', 'aeocao'],
    'rumano y polaco' => ['ășțłż', 'astlz'],
    'aleman y nordico' => ['ßæøå', 'ssaeoa'],
    'otra escritura se queda como esta' => ['Wáng 王', 'wang 王'],
])->group('RF-PD-15');
