<?php

declare(strict_types=1);

use App\Console\Commands\Quality\Support\RequirementRange;

/*
 * El lector de identificadores del Anexo A.
 *
 * Es la pieza por la que se cuela el fallo caro de la trazabilidad, y no por
 * reventar: por DAR VERDE sin saber leer. Asi se quedo RF-ID-09 sin tarea que lo
 * construyera hasta que lo encontro una persona. Un rango que no se expande son
 * seis requisitos que nadie vigila, y el comando no tiene forma de notarlo.
 */

it('expande un identificador suelto', function (): void {
    expect(RequirementRange::expand('RS-08'))->toBe(['RS-08']);
})->group('RQ-13');

it('expande un rango a todos sus identificadores', function (): void {
    // El caso RF-ID-09: seis requisitos escritos como uno.
    expect(RequirementRange::expand('RF-ID-04..09'))
        ->toBe(['RF-ID-04', 'RF-ID-05', 'RF-ID-06', 'RF-ID-07', 'RF-ID-08', 'RF-ID-09']);
})->group('RQ-13');

it('lee RNF-M-01 como RNF-M y no como RN', function (): void {
    // La alternancia de prefijos esta ordenada a proposito. Si se leyera como
    // RN, `RNF-M-01` se convertiria en un requisito inexistente y el real
    // quedaria sin vigilancia.
    expect(RequirementRange::expand('RNF-M-01'))->toBe(['RNF-M-01']);
    expect(RequirementRange::expand('RN-05'))->toBe(['RN-05']);
})->group('RQ-13');

it('rechaza un rango invertido en vez de devolver una lista vacia', function (): void {
    // Una lista vacia seria el fallo silencioso: cero requisitos citados y cero
    // ruido. Tiene que doler.
    expect(fn (): array => RequirementRange::expand('RF-AT-09..05'))
        ->toThrow(InvalidArgumentException::class, 'Rango invertido');
})->group('RQ-13');

it('rechaza lo que no es un identificador', function (): void {
    expect(fn (): array => RequirementRange::expand('§9 completo'))
        ->toThrow(InvalidArgumentException::class);
})->group('RQ-13');

it('no confunde un identificador con el prefijo de otro mas largo', function (): void {
    // `RF-AT-081` no es RF-AT-08 seguido de un 1.
    expect(RequirementRange::findAll('RF-AT-081'))->toBe([]);
})->group('RQ-13');

it('encuentra todos los identificadores de una fila del Anexo A, en orden', function (): void {
    $row = '**RF-AT-01**, RF-AT-05..07, RN-05 y RNF-M-01..02';

    expect(RequirementRange::findAll($row))->toBe([
        'RF-AT-01',
        'RF-AT-05', 'RF-AT-06', 'RF-AT-07',
        'RN-05',
        'RNF-M-01', 'RNF-M-02',
    ]);
})->group('RQ-13');

it('devuelve como residuo el texto que no ha sabido leer', function (): void {
    // «§9 completo» es una entrada real del Anexo A. No es un fallo, pero tiene
    // que ser visible: lo que desaparece en silencio es lo que se pierde.
    expect(RequirementRange::residue('RF-AT-01, §9 completo'))->toBe('§9 completo');
})->group('RQ-13');

it('no deja residuo cuando ha leido la fila entera', function (): void {
    expect(RequirementRange::residue('RF-AT-01, RF-AT-05..07.'))->toBe('');
})->group('RQ-13');
