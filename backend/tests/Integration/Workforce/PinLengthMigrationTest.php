<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `employees.pin_length` (RF-ID-09, ADR-050): las dos invariantes viven en el
 * esquema.
 *
 * La ida y vuelta de la migracion se prueba en
 * `tests/Integration/Schema/PinLengthMigrationRoundTripTest.php`: desde la 2.2.0
 * es no transaccional (su `VALIDATE` va despues del `COMMIT`) y ya no se puede
 * ensayar dentro de la transaccion de esta prueba.
 */

uses(RefreshDatabase::class);

it('no admite una longitud de siete', function (): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());

    expect(static fn () => DB::table('employees')->where('uuid', $uuid)->update([
        'pin_hash' => '$2y$04$abcdefghijklmnopqrstuv',
        'pin_issued_at' => now(),
        'pin_length' => 7,
    ]))->toThrow(QueryException::class);
})->group('RF-ID-09');

it('no admite un PIN sin longitud ni una longitud sin PIN', function (array $fila): void {
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site());

    expect(static fn () => DB::table('employees')->where('uuid', $uuid)->update($fila))
        ->toThrow(QueryException::class);
})->with([
    'hash sin longitud' => [['pin_hash' => '$2y$04$abcdefghijklmnopqrstuv', 'pin_issued_at' => '2026-10-06T09:00:00Z', 'pin_length' => null]],
    'longitud sin hash' => [['pin_length' => 8]],
])->group('RF-ID-09');
