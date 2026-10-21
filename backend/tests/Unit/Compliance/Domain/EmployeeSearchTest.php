<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\InvalidEmployeeSearch;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearchCriterion;

/*
 * El criterio de busqueda de la persona de la exportacion legal (RF-IN-05,
 * V21-3-PA-1).
 *
 * Los limites son los que impiden que la busqueda sea el directorio de
 * plantilla que el `auditor` no tiene (RF-ID-02): tres caracteres como minimo
 * una vez recortado, o un documento exacto.
 */

it('busca por texto con el termino recortado', function (): void {
    $busqueda = EmployeeSearch::byText('  amrani  ');

    expect($busqueda->criterion)->toBe(EmployeeSearchCriterion::NAME_OR_CODE)
        ->and($busqueda->text)->toBe('amrani')
        ->and($busqueda->nationalId)->toBeNull()
        ->and($busqueda->isByNationalId())->toBeFalse();
})->group('RF-IN-05');

it('admite exactamente tres caracteres', function (): void {
    expect(EmployeeSearch::byText('Ana')->text)->toBe('Ana')
        // Tres caracteres, no tres bytes: «Íñi» son seis en UTF-8.
        ->and(EmployeeSearch::byText('Íñi')->text)->toBe('Íñi');
})->group('RF-IN-05');

it('rechaza un termino de menos de tres caracteres una vez recortado', function (string $termino): void {
    expect(static fn (): EmployeeSearch => EmployeeSearch::byText($termino))
        ->toThrow(InvalidEmployeeSearch::class);
})->with([
    'vacio' => [''],
    'dos letras' => ['am'],
    'tres espacios' => ['   '],
    'dos letras entre espacios' => ['  am  '],
    // `trim()` no quita los separadores Unicode: tres U+3000 contaban como
    // tres caracteres y pasaban el minimo.
    'tres espacios ideograficos' => ["\u{3000}\u{3000}\u{3000}"],
    'dos letras entre espacios no separables' => ["\u{00A0}am\u{00A0}"],
    'dos letras entre espacios estrechos' => ["\u{202F}am\u{2009}"],
])->group('RF-IN-05', 'RS-05');

it('recorta tambien los separadores Unicode, y solo de los extremos', function (): void {
    expect(EmployeeSearch::byText("\u{3000}Pérez\u{00A0}Amrani\u{3000}")->text)->toBe("Pérez\u{00A0}Amrani");
})->group('RF-IN-05');

it('dice en el rechazo que cota fallo y cuanto vale, para traducir el 422', function (): void {
    $corto = rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byText('am'));
    $largo = rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byText(str_repeat('a', 101)));
    $documento = rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byNationalId('1-2-3'));

    \assert($corto instanceof InvalidEmployeeSearch && $largo instanceof InvalidEmployeeSearch && $documento instanceof InvalidEmployeeSearch);

    expect([$corto->field, $corto->bound, $corto->limit])->toBe(['q', InvalidEmployeeSearch::MIN, EmployeeSearch::MIN_TEXT_LENGTH])
        ->and([$largo->field, $largo->bound, $largo->limit])->toBe(['q', InvalidEmployeeSearch::MAX, EmployeeSearch::MAX_TEXT_LENGTH])
        ->and([$documento->field, $documento->bound, $documento->limit])->toBe(['national_id', InvalidEmployeeSearch::MIN, 4]);
})->group('RF-IN-05');

it('dice en el mensaje del rechazo que cota fallo y cuanto vale', function (?InvalidEmployeeSearch $rechazo, string $mensaje): void {
    expect($rechazo?->getMessage())->toBe($mensaje);
})->with([
    'texto corto' => [
        fn (): ?InvalidEmployeeSearch => rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byText('am')),
        'El termino de busqueda necesita al menos 3 caracteres una vez recortado.',
    ],
    'texto largo' => [
        fn (): ?InvalidEmployeeSearch => rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byText(str_repeat('a', 101))),
        'El termino de busqueda admite como mucho 100 caracteres.',
    ],
    'documento corto' => [
        fn (): ?InvalidEmployeeSearch => rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byNationalId('1-2-3')),
        'El documento de identidad necesita al menos 4 caracteres una vez quitados espacios, puntos y guiones.',
    ],
])->group('RF-IN-05');

it('rechaza un termino de mas de cien caracteres', function (): void {
    expect(EmployeeSearch::byText(str_repeat('a', 100))->text)->toHaveLength(100)
        ->and(static fn (): EmployeeSearch => EmployeeSearch::byText(str_repeat('a', 101)))
        ->toThrow(InvalidEmployeeSearch::class);
})->group('RF-IN-05');

it('busca por documento normalizado como en el alta', function (): void {
    $busqueda = EmployeeSearch::byNationalId('12.345.678-z');

    expect($busqueda->criterion)->toBe(EmployeeSearchCriterion::NATIONAL_ID)
        ->and($busqueda->nationalId?->value)->toBe('12345678Z')
        ->and($busqueda->text)->toBeNull()
        ->and($busqueda->isByNationalId())->toBeTrue();
})->group('RF-IN-05', 'RL-08');

it('rechaza un documento que normalizado se queda corto', function (string $documento): void {
    expect(static fn (): EmployeeSearch => EmployeeSearch::byNationalId($documento))
        ->toThrow(InvalidEmployeeSearch::class);
})->with([
    'solo separadores' => ['----'],
    'tres caracteres con separadores' => ['1-2-3'],
])->group('RF-IN-05', 'RL-08');

it('nunca repite el termino en el mensaje del rechazo', function (): void {
    // Regla dura 21: la excepcion puede acabar en el registro tecnico, y el
    // termino es un nombre o un DNI.
    $documento = rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byNationalId('9-9-9'));
    $termino = rechazoDeBusquedaLegal(static fn (): EmployeeSearch => EmployeeSearch::byText('Yo'));

    expect($documento)->not->toBeNull()
        ->and($termino)->not->toBeNull();
    \assert($documento instanceof InvalidEmployeeSearch && $termino instanceof InvalidEmployeeSearch);

    expect($documento->getMessage())->not->toContain('9-9-9')
        ->and($documento->getMessage())->not->toContain('999')
        ->and($documento->field)->toBe('national_id')
        ->and($termino->getMessage())->not->toContain('Yo')
        ->and($termino->field)->toBe('q');
})->group('RF-IN-05', 'RS-05');

function rechazoDeBusquedaLegal(Closure $construir): ?InvalidEmployeeSearch
{
    try {
        $construir();
    } catch (InvalidEmployeeSearch $rechazo) {
        return $rechazo;
    }

    return null;
}

it('nombra los criterios con el vocabulario del contrato y de ADR-037', function (): void {
    expect(array_map(static fn (EmployeeSearchCriterion $c): string => $c->value, EmployeeSearchCriterion::cases()))
        ->toBe(['name_or_code', 'national_id']);
})->group('RF-IN-05', 'RS-05');
