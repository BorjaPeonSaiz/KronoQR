<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exception\InvalidJurisdiction;
use App\Modules\Shared\Domain\ValueObject\Jurisdiction;

/*
 * La jurisdiccion de un perfil de cumplimiento (RF-PD-07, ADR-057 enmienda del
 * 10-10-2026, decision D5 del bloque 5 de la 2.2.1).
 *
 * Lee el codigo exactamente como el CHECK compliance_profiles_chk_jurisdiction_format
 * y SIN NORMALIZAR: si PHP aceptara `es` y el esquema no —o al reves—, el pais
 * se leeria distinto en los dos sitios y el suelo español se podria esquivar.
 */

it('admite un pais y un pais con subdivision', function (string $code, string $country): void {
    $jurisdiction = Jurisdiction::fromCode($code);

    expect($jurisdiction->code)->toBe($code)
        ->and($jurisdiction->country())->toBe($country);
})->with([
    'España' => ['ES', 'ES'],
    'Canarias' => ['ES-CN', 'ES'],
    'Portugal' => ['PT', 'PT'],
    'subdivision de una cifra' => ['FR-7', 'FR'],
    'subdivision de tres' => ['GB-ENG', 'GB'],
])->group('RF-PD-07');

it('rechaza lo que el esquema rechaza, sin normalizarlo', function (string $code): void {
    expect(fn (): Jurisdiction => Jurisdiction::fromCode($code))
        ->toThrow(InvalidJurisdiction::class, 'Jurisdiction "'.$code.'" is not an ISO 3166-1 alpha-2 code');
})->with([
    'minusculas' => ['es'],
    'espacio delante' => [' ES'],
    'espacio detras' => ['ES '],
    'salto de linea detras' => ["ES\n"],
    'alfa-3' => ['ESP'],
    'una letra' => ['E'],
    'guion sin subdivision' => ['ES-'],
    'subdivision de cuatro' => ['ES-ABCD'],
    'subdivision en minusculas' => ['ES-cn'],
    'cifras en el pais' => ['E1'],
    'prefijo' => ['XES'],
    'vacio' => [''],
])->group('RF-PD-07');

it('compara por codigo completo', function (): void {
    expect(Jurisdiction::fromCode('ES')->equals(Jurisdiction::fromCode('ES')))->toBeTrue()
        ->and(Jurisdiction::fromCode('ES')->equals(Jurisdiction::fromCode('ES-CN')))->toBeFalse();
})->group('RF-PD-07');
