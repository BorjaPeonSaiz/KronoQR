<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\SignOffReason;
use App\Modules\Shared\Domain\ValueObject\SignOffReasonViolation;

/*
 * El motivo de una firma de consola con relevancia legal: la revision de una
 * purga (RL-04, ADR-057 enmienda §6) y la correccion de la zona del centro
 * (RN-23, ADR-056 §3). De 10 a 1000 caracteres, contados como caracteres y no
 * como bytes, sin los espacios de los extremos. El rechazo lo construye quien
 * firma; aqui se comprueba que recibe el fallo y el limite correctos.
 */

/**
 * El rechazo de prueba: lleva el fallo y el limite en el mensaje.
 */
function signOffRechazo(): Closure
{
    return static fn (SignOffReasonViolation $violation, int $limit): RuntimeException => new RuntimeException($violation->name.':'.$limit);
}

it('admite de diez a mil caracteres', function (int $length): void {
    expect(SignOffReason::fromText(str_repeat('a', $length), signOffRechazo())->text)->toBe(str_repeat('a', $length));
})->with([10, 1000])->group('RL-04', 'RN-23');

it('rechaza nueve y mil uno con el fallo y el limite de cada uno', function (int $length, string $mensaje): void {
    expect(fn (): SignOffReason => SignOffReason::fromText(str_repeat('a', $length), signOffRechazo()))
        ->toThrow(RuntimeException::class, $mensaje);
})->with([
    'nueve' => [9, 'TooShort:10'],
    'mil uno' => [1001, 'TooLong:1000'],
])->group('RL-04', 'RN-23');

it('recorta los espacios de los extremos antes de contar', function (): void {
    expect(SignOffReason::fromText("  Incidente INC-7 investigado  \n", signOffRechazo())->text)->toBe('Incidente INC-7 investigado')
        ->and(fn (): SignOffReason => SignOffReason::fromText('   corto    ', signOffRechazo()))
        ->toThrow(RuntimeException::class, 'TooShort:10');
})->group('RL-04', 'RN-23');

it('cuenta caracteres multibyte como uno', function (): void {
    expect(SignOffReason::fromText(str_repeat('ñ', 10), signOffRechazo())->text)->toBe(str_repeat('ñ', 10))
        ->and(SignOffReason::fromText(str_repeat('ñ', 1000), signOffRechazo())->text)->toBe(str_repeat('ñ', 1000))
        ->and(fn (): SignOffReason => SignOffReason::fromText(str_repeat('ñ', 9), signOffRechazo()))
        ->toThrow(RuntimeException::class, 'TooShort:10')
        ->and(fn (): SignOffReason => SignOffReason::fromText(str_repeat('ñ', 1001), signOffRechazo()))
        ->toThrow(RuntimeException::class, 'TooLong:1000');
})->group('RL-04', 'RN-23');

it('fija los limites en diez y mil', function (): void {
    expect(SignOffReason::MIN_LENGTH)->toBe(10)
        ->and(SignOffReason::MAX_LENGTH)->toBe(1000);
})->group('RL-04', 'RN-23');
