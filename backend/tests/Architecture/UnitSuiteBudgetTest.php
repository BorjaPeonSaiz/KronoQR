<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * Un solo presupuesto de duracion para la suite unitaria (Q1 de la verificacion
 * de la 2.1.0, RQ-14).
 *
 * La verificacion conto cuatro cifras para el mismo umbral: 2 s en
 * `phpunit.xml` y en el doc 03, 4 s en el doc 02 con un «aspiracional» de 2 s, y
 * 5 s en el `Makefile`, que era la unica que se aplicaba. Quien leia un
 * documento creia que la CI exigia algo que no exigia.
 *
 * La fuente es el `Makefile` (`UNIT_SUITE_MAX_SECONDS`), porque es lo que
 * ejecuta `make test-unit` en la CI. Los demas sitios remiten a la variable por
 * su nombre y, si dan la cifra, dan esa. Esta prueba lo comprueba en las dos
 * direcciones: que remiten y que no se desvian.
 */

/**
 * La cifra que aplica `make test-unit`.
 */
function unitSuiteBudgetFromMakefile(): string
{
    preg_match('/^UNIT_SUITE_MAX_SECONDS \?= (\d+(?:\.\d+)?)$/m', Repo::contents('Makefile'), $match);

    return $match[1] ?? '';
}

/**
 * Las cifras en segundos que un texto asocia a `UNIT_SUITE_MAX_SECONDS`.
 *
 * Se lee la primera cifra seguida de « s» que aparece despues del nombre de la
 * variable, sin cruzar un punto y aparte: «`UNIT_SUITE_MAX_SECONDS` del
 * `Makefile`, 5 s» da `5`.
 *
 * @return list<string>
 */
function unitSuiteBudgetFiguresIn(string $text): array
{
    preg_match_all('/UNIT_SUITE_MAX_SECONDS[^.\d]{0,80}?(\d+(?:,\d+)?) s\b/u', $text, $matches);

    return array_map(static fn (string $figure): string => str_replace(',', '.', $figure), $matches[1]);
}

/**
 * Los ficheros que hablan del presupuesto y tienen que remitir al `Makefile`.
 *
 * @return array<string, array{0: string}>
 */
function unitSuiteBudgetMirrors(): array
{
    return [
        'doc 02 §9.2' => ['docs/02-stack-tecnologico-y-plan-implementacion.md'],
        'doc 03' => ['docs/03-agentes-y-skills-ia.md'],
        'phpunit.xml' => ['backend/phpunit.xml'],
    ];
}

it('lee el presupuesto de la suite unitaria del Makefile', function (): void {
    // El control: sin el, las de abajo compararian contra una cadena vacia.
    expect(unitSuiteBudgetFromMakefile())->toMatch('/^\d+(\.\d+)?$/');
})->group('RQ-14');

it('remite al presupuesto del Makefile con la misma cifra', function (string $relative): void {
    $figures = unitSuiteBudgetFiguresIn(Repo::contents($relative));

    expect($figures)->not->toBe([], $relative.' no remite a UNIT_SUITE_MAX_SECONDS con su cifra en segundos.')
        ->and(array_unique($figures))->toBe(
            [unitSuiteBudgetFromMakefile()],
            $relative.' da una cifra distinta de la del Makefile para la suite unitaria. El Makefile es la '
            .'unica fuente (Q1): cambia la cifra alli y en las frases que remiten a el, a la vez.',
        );
})->with(unitSuiteBudgetMirrors())->group('RQ-14');

it('no conserva las cifras antiguas del presupuesto', function (string $relative, string $frase): void {
    // Las cuatro cifras que encontro la verificacion. Si vuelven, vuelve Q1.
    expect(Repo::contents($relative))->not->toContain($frase);
})->with([
    'phpunit.xml, 2 s' => ['backend/phpunit.xml', 'por debajo de 2 s'],
    'doc 03, 2 segundos' => ['docs/03-agentes-y-skills-ia.md', 'en menos de 2 segundos'],
    'doc 02, aspiracional' => ['docs/02-stack-tecnologico-y-plan-implementacion.md', 'aspiracional de 2 s'],
    'doc 02, 4 s' => ['docs/02-stack-tecnologico-y-plan-implementacion.md', '4 s en el contenedor de desarrollo'],
])->group('RQ-14');

it('reconoce la cifra que acompaña a la variable y no la de otra frase', function (): void {
    // La extraccion de arriba es la que hace significativa la prueba: si leyera
    // cualquier numero del fichero, cualquier documento pasaria.
    $texto = 'El presupuesto es `UNIT_SUITE_MAX_SECONDS` del `Makefile`, 5 s. La CI tarda 2 s.';

    expect(unitSuiteBudgetFiguresIn($texto))->toBe(['5']);
})->group('RQ-14');
