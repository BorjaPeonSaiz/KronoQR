<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Exception\InvalidResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Domain\ValueObject\ResponsibleReference;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/*
 * Como se nombra en consola la cuenta que firma un asiento: su uuid o su
 * correo, nunca el id interno (2.3.0 bloque 3, V21-7-SC-1; doc 01 §7.2).
 */

it('lee un uuid y lo normaliza a minusculas', function (): void {
    $reference = ResponsibleReference::parse('  0192F1A4-1B2C-7D3E-8F40-A1B2C3D4E5F6 ');

    expect($reference->isUuid())->toBeTrue()
        ->and($reference->uuid)->toBe('0192f1a4-1b2c-7d3e-8f40-a1b2c3d4e5f6')
        ->and($reference->email)->toBeNull()
        ->and($reference->value())->toBe('0192f1a4-1b2c-7d3e-8f40-a1b2c3d4e5f6');
})->group('RL-04');

it('lee un correo y lo normaliza a minusculas', function (): void {
    $reference = ResponsibleReference::parse(' Marta.RRHH@Hotel.ES ');

    expect($reference->isUuid())->toBeFalse()
        ->and($reference->email)->toBe('marta.rrhh@hotel.es')
        ->and($reference->uuid)->toBeNull()
        ->and($reference->value())->toBe('marta.rrhh@hotel.es');
})->group('RL-04');

/** La clave del motivo con el que se rechaza el valor, o `null` si se acepta. */
function responsibleReferenceRejection(string $valor): ?string
{
    try {
        ResponsibleReference::parse($valor);
    } catch (InvalidResponsibleReference $e) {
        return $e->translationKey;
    }

    return null;
}

it('rechaza un id numerico con su propio motivo, aunque fuera una cuenta', function (string $valor): void {
    expect(responsibleReferenceRejection($valor))->toBe('responsible.numeric_id');
})->with(['7', ' 42 ', '0', '000123'])->group('RL-04');

it('rechaza lo vacio y lo que no es uuid ni correo', function (string $valor, string $clave): void {
    expect(responsibleReferenceRejection($valor))->toBe($clave);
})->with([
    'vacio' => ['   ', 'responsible.missing'],
    'letras y cifras' => ['12abc', 'responsible.malformed'],
    'negativo' => ['-7', 'responsible.malformed'],
    'sin arroba' => ['marta.hotel.es', 'responsible.malformed'],
    'sin dominio con punto' => ['marta@hotel', 'responsible.malformed'],
    'dos arrobas' => ['marta@@hotel.es', 'responsible.malformed'],
    'con espacios' => ['marta perez@hotel.es', 'responsible.malformed'],
    'uuid cortado' => ['0192f1a4-1b2c-7d3e-8f40', 'responsible.malformed'],
    'correo de mas de 190' => [str_repeat('a', 185).'@hotel.es', 'responsible.malformed'],
])->group('RL-04');

it('no lleva el valor en el mensaje tecnico, que puede ser un correo', function (): void {
    try {
        ResponsibleReference::parse('marta perez@hotel.es');
    } catch (InvalidResponsibleReference $e) {
        expect($e->getMessage())->not->toContain('marta');

        return;
    }

    throw new LogicException('Tenia que rechazarlo.');
})->group('RL-04');

/**
 * @param  list<UserRole>  $roles
 */
function responsibleReferenceAccount(array $roles, bool $active = true): ResponsibleAccount
{
    return new ResponsibleAccount(7, '0192f1a4-1b2c-7d3e-8f40-a1b2c3d4e5f6', 'Cuenta de RRHH', 'rrhh@hotel.es', $active, $roles);
}

it('firma si esta activa y tiene alguno de los roles pedidos', function (array $roles, array $pedidos, bool $activa, bool $firma): void {
    /** @var list<UserRole> $roles */
    /** @var list<UserRole> $pedidos */
    expect(responsibleReferenceAccount($roles, $activa)->canSignAs($pedidos))->toBe($firma);
})->with([
    'rrhh donde se pide admin o rrhh' => [[UserRole::RRHH], [UserRole::ADMIN, UserRole::RRHH], true, true],
    'admin donde se pide admin' => [[UserRole::ADMIN], [UserRole::ADMIN], true, true],
    'rrhh donde se pide admin' => [[UserRole::RRHH], [UserRole::ADMIN], true, false],
    'auditor donde se pide admin o rrhh' => [[UserRole::AUDITOR], [UserRole::ADMIN, UserRole::RRHH], true, false],
    'admin dado de baja' => [[UserRole::ADMIN], [UserRole::ADMIN], false, false],
    'sin roles pedidos, una de gestion' => [[UserRole::AUDITOR], [], true, true],
    'sin roles pedidos, un empleado' => [[UserRole::EMPLEADO], [], true, false],
    'sin roles pedidos, sin ningun rol' => [[], [], true, false],
])->group('RL-04', 'RS-05');

it('confirma con el correo de la cuenta sin distinguir mayusculas, y con nada mas', function (string $correo, bool $confirma): void {
    expect(responsibleReferenceAccount([UserRole::RRHH])->isConfirmedBy($correo))->toBe($confirma);
})->with([
    'el mismo' => ['rrhh@hotel.es', true],
    'en mayusculas y con espacios' => [' RRHH@Hotel.ES ', true],
    'otro' => ['admin@hotel.es', false],
    'vacio' => ['', false],
    'el uuid' => ['0192f1a4-1b2c-7d3e-8f40-a1b2c3d4e5f6', false],
])->group('RL-04');
