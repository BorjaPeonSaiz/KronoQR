<?php

declare(strict_types=1);

use App\Modules\Workforce\Domain\Exception\EmployeeCodeAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\InvalidEmployeeCode;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;

/*
 * Las excepciones del codigo de empleado no lo llevan en el mensaje (ADR-048,
 * regla dura 21, RF-PD-15).
 *
 * El codigo identifica a una persona dentro de la instalacion y es la mitad de
 * la credencial del portal (ADR-015). El mensaje de una excepcion acaba en el
 * log tecnico y en `error_events`, y de ahi en el paquete de diagnostico.
 */

it('EmployeeCodeAlreadyTaken no lleva el codigo', function (): void {
    expect(EmployeeCodeAlreadyTaken::make()->getMessage())->toBe('Ya existe un empleado con ese codigo.');
})->group('RF-PD-15', 'RL-19');

it('InvalidEmployeeCode no lleva el valor rechazado', function (string $valor, string $mensaje): void {
    try {
        EmployeeCode::fromString($valor);
        $capturado = null;
    } catch (InvalidEmployeeCode $exception) {
        $capturado = $exception->getMessage();
    }

    expect($capturado)->toBe($mensaje)
        ->and((string) $capturado)->not->toContain(mb_strtoupper(trim($valor)));
})->with([
    'demasiado largo' => [str_repeat('A1', 20), 'El codigo de empleado tiene 40 caracteres y el maximo son 32.'],
    'con simbolos' => ['HTL-2019', 'El codigo de empleado solo admite letras mayusculas y digitos.'],
])->group('RF-PD-15', 'RL-19');
