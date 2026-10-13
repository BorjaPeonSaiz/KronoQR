<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\Jurisdiction;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/*
 * El suelo legal de la retencion del registro (RL-02, RF-PD-07, ADR-057
 * enmienda del 10-10-2026). Limite cerrado: el art. 34.9 ET obliga a conservar
 * «durante cuatro años», y cuatro cumple.
 */

it('admite el plazo igual o mayor que el suelo y rechaza el menor', function (int $years, bool $admitted): void {
    $floor = LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), 4);

    expect($floor->admits($years))->toBe($admitted);
})->with([
    'tres' => [3, false],
    'cuatro' => [4, true],
    'cinco' => [5, true],
])->group('RF-PD-07', 'RL-02');

it('dice si el suelo sale del catalogo o es el de omision', function (): void {
    $spain = LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES-CN'), 4);
    $portugal = LegalRetentionFloor::byDefault(Jurisdiction::fromCode('PT'), 1);

    expect($spain->fromCatalog)->toBeTrue()
        ->and($spain->years)->toBe(4)
        ->and($spain->jurisdiction->code)->toBe('ES-CN')
        ->and($portugal->fromCatalog)->toBeFalse()
        ->and($portugal->years)->toBe(1)
        ->and($portugal->admits(1))->toBeTrue();
})->group('RF-PD-07', 'RL-02');

it('no se construye por debajo de un año', function (int $years): void {
    expect(fn (): LegalRetentionFloor => LegalRetentionFloor::fromCatalog(Jurisdiction::fromCode('ES'), $years))
        ->toThrow(InvalidArgumentException::class, 'at least 1 year, got '.$years)
        ->and(fn (): LegalRetentionFloor => LegalRetentionFloor::byDefault(Jurisdiction::fromCode('ZZ'), $years))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1])->group('RF-PD-07', 'RL-02');

it('admite el suelo minimo de un año', function (): void {
    expect(LegalRetentionFloor::byDefault(Jurisdiction::fromCode('ZZ'), 1)->years)->toBe(1);
})->group('RF-PD-07');
