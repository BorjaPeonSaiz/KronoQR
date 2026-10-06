<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\PinLength;

/*
 * La longitud del PIN (RF-ID-09, ADR-050): seis u ocho cifras, y nada mas.
 */

it('solo tiene dos longitudes, seis y ocho', function (): void {
    expect(array_map(static fn (PinLength $length): int => $length->value, PinLength::cases()))
        ->toBe([6, 8]);
})->group('RF-ID-09');

it('no se puede construir una longitud de siete ni ninguna otra', function (int $longitud): void {
    // Ni el teclado del quiosco ni `IssuedPin.pin` contemplan otra cosa: un
    // rango 6-8 dejaria pasar el siete.
    expect(PinLength::tryFrom($longitud))->toBeNull()
        ->and(static fn (): PinLength => PinLength::from($longitud))->toThrow(ValueError::class);
})->with([0, 4, 5, 7, 9, 12])->group('RF-ID-09');

it('da el mayor PIN representable de cada longitud', function (): void {
    expect(PinLength::SIX->maximum())->toBe(999999)
        ->and(PinLength::EIGHT->maximum())->toBe(99999999);
})->group('RF-ID-09');

it('reconoce la forma de un PIN de su longitud y solo esa', function (PinLength $length, string $pin, bool $encaja): void {
    expect($length->fits($pin))->toBe($encaja);
})->with([
    'seis cifras en seis' => [PinLength::SIX, '012345', true],
    'ocho cifras en seis' => [PinLength::SIX, '01234567', false],
    'ocho cifras en ocho' => [PinLength::EIGHT, '01234567', true],
    'siete cifras en ocho' => [PinLength::EIGHT, '0123456', false],
    'letras en seis' => [PinLength::SIX, '12a456', false],
    'signo en seis' => [PinLength::SIX, '-12345', false],
])->group('RF-ID-09');
