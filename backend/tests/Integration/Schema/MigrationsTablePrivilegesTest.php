<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * El rol de la aplicacion solo LEE la tabla `migrations` (V4-SC-1 de la
 * verificacion final de la 2.2.0; regla dura 6, ADR-033, ADR-042; RF-PD-10).
 *
 * Quien escribe en `migrations` decide que ejecuta el migrador en la siguiente
 * actualizacion: borrar la fila de la provision hace que se vuelva a ejecutar y
 * devuelva `UPDATE` y `DELETE` sobre `audit_log`; insertar el nombre de una
 * migracion futura hace que no se aplique nunca. Contra PostgreSQL de verdad y
 * con el rol de la aplicacion como conexion por defecto: una prueba de permisos
 * con el propietario pasaria siempre.
 */

uses(RefreshDatabase::class);

const MIGRATIONS_TABLE_PRIVILEGES_MIGRATION = '2026_10_09_100000_revoke_application_writes_on_migrations_table';

const MIGRATIONS_TABLE_PRIVILEGES_PROVISION = '2026_08_19_099000_provision_database_privileges';

/**
 * Lo que dice el catalogo, no lo que se intenta: `has_table_privilege` para el
 * rol de la aplicacion, con la conexion que se le pase.
 *
 * @return array<string, bool>
 */
function migrationsTablePrivileges(?ConnectionInterface $connection = null, string $table = 'migrations'): array
{
    $connection ??= DB::connection();
    $application = Config::string('database.roles.application');
    $result = [];

    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
        /** @var object{granted: bool}|null $row */
        $row = $connection->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            [$application, $table, $privilege],
        );

        $result[$privilege] = (bool) $row?->granted;
    }

    return $result;
}

/**
 * Cuantos pasos de `migrate:rollback` hacen falta para deshacer esta migracion.
 * Copia deliberada de la de `ResanitizeErrorHistoryTest`: una funcion global de
 * Pest definida en otro fichero solo existe si ese fichero se ha cargado.
 */
function migrationsTablePrivilegesStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === MIGRATIONS_TABLE_PRIVILEGES_MIGRATION) {
            return $steps;
        }
    }

    return 0;
}

it('deja al rol de la aplicacion solo SELECT sobre la tabla migrations', function (): void {
    expect(migrationsTablePrivileges())->toBe([
        'SELECT' => true,
        'INSERT' => false,
        'UPDATE' => false,
        'DELETE' => false,
        'TRUNCATE' => false,
    ]);
})->group('RF-PD-10', 'RS-07');

it('el motor rechaza que la aplicacion borre, inserte o reescriba una migracion', function (string $what, Closure $write): void {
    try {
        // Dentro de un SAVEPOINT: en PostgreSQL un error aborta la transaccion
        // entera de la prueba.
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('42501', $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail('PostgreSQL ha permitido «'.$what.'» al rol de la aplicacion sobre la tabla migrations.');
})->with([
    'borrar la fila de la provision' => [
        'DELETE',
        static fn () => DB::table('migrations')->where('migration', MIGRATIONS_TABLE_PRIVILEGES_PROVISION)->delete(),
    ],
    'anotar una migracion futura' => [
        'INSERT',
        static fn () => DB::table('migrations')->insert(['migration' => '2099_01_01_000000_future', 'batch' => 1]),
    ],
    'cambiar el lote' => [
        'UPDATE',
        static fn () => DB::table('migrations')->update(['batch' => 999]),
    ],
])->group('RF-PD-10', 'RS-07');

it('la aplicacion sigue pudiendo leer la tabla, que es lo que usa product:doctor', function (): void {
    expect(DB::table('migrations')->where('migration', MIGRATIONS_TABLE_PRIVILEGES_MIGRATION)->exists())->toBeTrue();
})->group('RF-PD-10', 'RF-PD-13');

it('volver a ejecutar la provision no devuelve la escritura sobre migrations', function (): void {
    /*
     * El escenario de V4-SC-1 por el que importa: alguien consigue que la
     * provision corra otra vez. Se ejecuta su `up()` con el rol de MIGRACION,
     * dentro de una transaccion que se deshace al final —los GRANT y REVOKE de
     * PostgreSQL son transaccionales—, para no dejar la base de pruebas con
     * `UPDATE` sobre `audit_log`.
     */
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $previousDefault = DB::getDefaultConnection();

    /** @var Migration $provision */
    $provision = require database_path('migrations/'.MIGRATIONS_TABLE_PRIVILEGES_PROVISION.'.php');

    \assert(method_exists($provision, 'up'));

    $migrator->beginTransaction();
    DB::setDefaultConnection($name);

    try {
        $provision->up();

        // La provision se ha ejecutado de verdad: ha vuelto a conceder las
        // cuatro operaciones sobre el resto del esquema...
        expect(migrationsTablePrivileges($migrator, 'employees')['UPDATE'])->toBeTrue()
            // ...pero no la escritura sobre `migrations`.
            ->and(migrationsTablePrivileges($migrator))->toMatchArray([
                'SELECT' => true,
                'INSERT' => false,
                'UPDATE' => false,
                'DELETE' => false,
            ]);
    } finally {
        DB::setDefaultConnection($previousDefault);
        $migrator->rollBack();
    }
})->group('RF-PD-10', 'RS-07');

it('su down() devuelve exactamente lo que habia y su up() lo vuelve a quitar', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);

    $steps = migrationsTablePrivilegesStepsBack($migrator);

    expect($steps)->toBeGreaterThan(0);

    try {
        [$back, $backOutput] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($back)->toBe(0, $backOutput)
            ->and(migrationsTablePrivileges($migrator))->toMatchArray([
                'SELECT' => true,
                'INSERT' => true,
                'UPDATE' => true,
                'DELETE' => true,
                'TRUNCATE' => false,
            ]);
    } finally {
        [$forward, $forwardOutput] = Commands::run('migrate --database='.$name);
    }

    expect($forward)->toBe(0, $forwardOutput)
        ->and(migrationsTablePrivileges($migrator))->toMatchArray([
            'SELECT' => true,
            'INSERT' => false,
            'UPDATE' => false,
            'DELETE' => false,
        ]);
})->group('RF-PD-10', 'RS-07');
