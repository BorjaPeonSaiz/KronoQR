<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * La tabla `license` nace vacia, con sus garantias, y sabe volver atras
 * (RF-PD-04, doc 01 §5).
 *
 * ## Lo que se afirma, mas alla de que la tabla exista
 *
 * 1. **Nace vacia.** «Sin licencia» es un estado normal del producto, no un
 *    fallo de instalacion: sembrar una licencia de prueba dejaria a un cliente
 *    con un plan que nadie contrato.
 * 2. **Una fila y solo una** (ADR-040: una licencia es un centro). Lo garantiza
 *    el indice unico sobre una expresion constante, no el adaptador.
 * 3. **Los `CHECK` estan en vigor**, no `NOT VALID`: la tabla esta vacia, asi
 *    que no hay nada que validar despues.
 * 4. **`down()` no destruye nada con obligacion de conservacion**: lo unico que
 *    desaparece es la clave activada, que el cliente vuelve a pegar, y cuya
 *    activacion sigue constando en `audit_log`.
 */

uses(RefreshDatabase::class);

it('la tabla nace vacia: sin licencia es un estado normal', function (): void {
    expect(DB::table('license')->count())->toBe(0);
})->group('RF-PD-04');

it('no admite una segunda fila', function (): void {
    DB::table('license')->insert(licenseRow('primera'));

    // Y no se consulta nada despues: un `INSERT` fallido deja abortada la
    // transaccion de PostgreSQL en la que corre la prueba, asi que cualquier
    // consulta posterior fallaria por eso y no por lo que se quiere afirmar.
    expect(static fn () => DB::table('license')->insert(licenseRow('segunda')))
        ->toThrow(QueryException::class);
})->group('RF-PD-04');

it('rechaza cifras de plan imposibles y vigencias invertidas', function (array $overrides): void {
    // La red que atrapa un `INSERT` hecho a mano en una madrugada de incidencia.
    // El dominio comprueba lo mismo al construir la licencia.
    expect(static fn () => DB::table('license')->insert(licenseRow('x', $overrides)))
        ->toThrow(QueryException::class);
})->with([
    'cero empleados' => [['max_employees' => 0]],
    'quioscos negativos' => [['max_devices' => -1]],
    'vigencia invertida' => [[
        'valid_from' => '2026-12-31T00:00:00Z',
        'valid_until' => '2026-01-01T00:00:00Z',
    ]],
    'features que no es una lista' => [['features' => '{"advanced_reports":true}']],
])->group('RF-PD-04');

it('sabe volver atras sin llevarse nada que haya que conservar', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    try {
        $steps = licenseStepsBackTo($migrator, '2026_09_08_100000_create_license_table');

        expect($steps)->toBeGreaterThan(0);

        [$exitCode] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($exitCode)->toBe(0)
            ->and($migrator->getSchemaBuilder()->hasTable('license'))->toBeFalse()
            // El registro horario sigue entero: revertir la licencia no puede
            // tocar ni una jornada (regla dura 15).
            ->and($migrator->getSchemaBuilder()->hasTable('shift_entries'))->toBeTrue()
            ->and($migrator->getSchemaBuilder()->hasTable('audit_log'))->toBeTrue();

        [$forward] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0);
    } finally {
        // Idempotente: la base de pruebas es compartida y dejarla sin la tabla
        // convertiria un fallo de asercion en un fallo en cascada.
        Commands::run('migrate --database='.$name);
    }

    expect($migrator->getSchemaBuilder()->hasTable('license'))->toBeTrue();
})->group('RF-PD-04');

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function licenseRow(string $customer, array $overrides = []): array
{
    return [
        'signed_key' => 'KQL1.'.$customer.'.firma',
        'license_id' => $customer,
        'customer_name' => $customer,
        'plan' => 'estandar',
        'max_employees' => 50,
        'max_devices' => 3,
        'features' => '[]',
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2025-12-15T10:00:00Z',
        'activated_at' => '2026-01-02T09:00:00Z',
        'activated_by_user_id' => null,
        'last_verified_at' => null,
        ...$overrides,
    ];
}

/**
 * Cuantos pasos de `migrate:rollback` deshacen la migracion indicada. Se cuenta
 * en lugar de fijar `--step=1` para que la siguiente migracion que entre no
 * rompa esta prueba.
 */
function licenseStepsBackTo(ConnectionInterface $connection, string $migration): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === $migration) {
            return $steps;
        }
    }

    return 0;
}
