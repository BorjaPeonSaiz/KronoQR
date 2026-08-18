<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\PhaseOrder;

/*
 * El orden real de ejecucion de las fases: 0 -> 1 -> 2 -> 5 -> 3 -> 4.
 *
 * Toda esta clase existe por un unico caso: que la Fase 5 se ejecuta ANTES que
 * la 3 y la 4. Con una comparacion numerica —`fase <= CURRENT_PHASE`— cerrar la
 * 5 daria por exigibles los requisitos de la 3 y la 4, que nadie ha empezado.
 * Ese es el caso que prueban estas pruebas; el resto es guarnicion.
 */

/** El orden declarado por el Anexo A, que es el que hay que probar. */
function phaseOrderUnderTest(): PhaseOrder
{
    return new PhaseOrder([0, 1, 2, 5, 3, 4]);
}

it('da por ejecutadas las fases anteriores en el orden real', function (): void {
    $order = phaseOrderUnderTest();

    expect($order->isExecutedBy(0, 2))->toBeTrue();
    expect($order->isExecutedBy(1, 2))->toBeTrue();
    expect($order->isExecutedBy(2, 2))->toBeTrue();
})->group('RQ-13');

it('no exige prueba a las fases 3 y 4 cuando se cierra la 5', function (): void {
    // EL caso. Numericamente 3 < 5 y 4 < 5, asi que una comparacion `<=` las
    // daria por hechas y `--check` se pondria rojo por trabajo que nadie ha
    // empezado. Un bloqueo que salta cuando no toca se acaba desactivando, y
    // con el se va el que si sirve.
    $order = phaseOrderUnderTest();

    expect($order->isExecutedBy(3, 5))->toBeFalse();
    expect($order->isExecutedBy(4, 5))->toBeFalse();
    expect($order->isExecutedBy(2, 5))->toBeTrue();
})->group('RQ-13');

it('trata una fase desconocida como error y no como fuera de alcance', function (): void {
    // Un `fase: 7` mal escrito en docs/requisitos.yaml dejaria de bloquear en
    // silencio si se tratara como «todavia no ejecutada».
    $order = phaseOrderUnderTest();

    expect(fn (): bool => $order->isExecutedBy(7, 2))
        ->toThrow(InvalidArgumentException::class, 'Fase desconocida: 7');
})->group('RQ-13');

it('rechaza un orden vacio o con fases repetidas', function (): void {
    expect(fn (): PhaseOrder => new PhaseOrder([]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): PhaseOrder => new PhaseOrder([0, 1, 1, 2]))
        ->toThrow(InvalidArgumentException::class, 'repite');
})->group('RQ-13');

it('describe el orden tal y como lo declara el documento', function (): void {
    expect(phaseOrderUnderTest()->describe())->toBe('0 -> 1 -> 2 -> 5 -> 3 -> 4');
})->group('RQ-13');
