<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `employees.pin_length` (RF-ID-09, ADR-050): las dos invariantes viven en el
 * esquema y la migracion sabe volver atras.
 *
 * El `down()` y el `up()` se ejecutan DENTRO de la transaccion de la prueba —en
 * PostgreSQL el DDL es transaccional—, asi que no tocan el esquema que ven las
 * demas suites que comparten la base de datos.
 */

uses(RefreshDatabase::class);

const PIN_LENGTH_MIGRATION_FILE = '2026_10_06_100000_add_pin_length_to_employees_table.php';

function migracionDeLongitudDelPin(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/'.PIN_LENGTH_MIGRATION_FILE);

    return $migration;
}

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

it('rellena con seis los PIN existentes y sabe volver atras', function (): void {
    // Con el rol de migracion, que es el dueño de la tabla (regla dura 6: el de
    // la aplicacion no tiene DDL). En su propia transaccion, que se deshace al
    // final: el esquema compartido no llega a cambiar para nadie mas.
    $previa = DB::getDefaultConnection();
    $conexion = config()->string('database.migrations.connection');
    DB::setDefaultConnection($conexion);
    DB::beginTransaction();

    try {
        $migration = migracionDeLongitudDelPin();

        \assert(method_exists($migration, 'down') && method_exists($migration, 'up'));

        // La plantilla se siembra en ESTA transaccion: el centro es unico por
        // instalacion (ADR-040) y otro sin confirmar lo bloquearia.
        $otra = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la migracion'));
        DB::table('employees')->where('uuid', $otra)->update([
            'pin_hash' => '$2y$04$abcdefghijklmnopqrstuv',
            'pin_issued_at' => '2026-10-06T09:00:00Z',
            'pin_length' => 6,
        ]);

        $migration->down();

        expect(Schema::connection($conexion)->hasColumn('employees', 'pin_length'))->toBeFalse();

        $migration->up();

        expect(Schema::connection($conexion)->hasColumn('employees', 'pin_length'))->toBeTrue()
            ->and(DB::table('employees')->where('uuid', $otra)->value('pin_length'))->toBe(6);
    } finally {
        DB::rollBack();
        DB::setDefaultConnection($previa);
    }
})->group('RF-ID-09');
