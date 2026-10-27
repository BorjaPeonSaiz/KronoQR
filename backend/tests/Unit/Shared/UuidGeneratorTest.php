<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\UuidGenerator;
use App\Modules\Shared\Infrastructure\Adapter\TimeOrderedUuidGenerator;
use Tests\Support\Shared\SequentialUuidGenerator;

/*
 * El puerto de identificadores (RV-3) y sus dos implementaciones: la de
 * produccion y el doble determinista. Suite Unit: sin base de datos.
 */

const UUID_GENERATOR_TEST_V7_SHAPE = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

it('genera UUID v7 canonicos en minusculas', function (): void {
    $generator = new TimeOrderedUuidGenerator;

    expect($generator)->toBeInstanceOf(UuidGenerator::class)
        ->and($generator->next())->toMatch(UUID_GENERATOR_TEST_V7_SHAPE);
})->group('RNF-M-03');

it('genera identificadores distintos y ordenados en el tiempo', function (): void {
    $generator = new TimeOrderedUuidGenerator;

    $first = $generator->next();
    usleep(2000);
    $second = $generator->next();

    // El v7 lleva el milisegundo delante: el segundo, dos milisegundos despues,
    // ordena detras. Es la localidad de indice que justifica v7 y no v4.
    expect($second)->not->toBe($first)
        ->and(strcmp($first, $second))->toBeLessThan(0);
})->group('RNF-M-03');

it('el doble de pruebas es determinista, tiene forma de v7 y ordena como se genero', function (): void {
    $generator = new SequentialUuidGenerator;

    $first = $generator->next();
    $second = $generator->next();

    expect($first)->toBe('00000000-0000-7000-8000-000000000001')
        ->and($second)->toBe('00000000-0000-7000-8000-000000000002')
        ->and($first)->toMatch(UUID_GENERATOR_TEST_V7_SHAPE)
        ->and(strcmp($first, $second))->toBeLessThan(0)
        ->and($generator->issued())->toBe(2);
})->group('RNF-M-03');
