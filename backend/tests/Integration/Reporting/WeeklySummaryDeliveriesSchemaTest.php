<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * El esquema de `weekly_summary_deliveries` (RF-PR-05, decision 6 de la ficha
 * 3.12).
 *
 * ## Lo que se defiende
 *
 * **Que la invariante la declara la base de datos y no PHP.** «Un resumen por
 * cuenta y semana» comprobado con un `SELECT` previo es una carrera: entre la
 * consulta y la escritura cabe otra pasada, y el resultado son dos correos con
 * los mismos nombres de la plantilla saliendo de la instalacion. Lo que la
 * cierra es el `UNIQUE (manager_user_id, week_start)`, y eso solo se puede
 * afirmar escribiendo contra PostgreSQL.
 *
 * **Y que la migracion sabe volver atras** (`/migracion-segura`: «una migracion
 * cuyo `down()` no se ha probado no tiene `down()`»).
 *
 * ## Por que el `down()` se prueba con `migrate:rollback`
 *
 * Por lo mismo que en la migracion de contraccion de la tarea 5.1: es el camino
 * por el que se ejecuta de verdad —el de `update.sh` cuando una actualizacion
 * falla y hay que regresar— y no el codigo de la migracion invocado a mano. Y
 * ademas no hay alternativa: el rol de la aplicacion **no es propietario** de
 * las tablas (ADR-033), asi que un `down()` llamado desde la conexion de la
 * suite ni siquiera puede soltarla.
 */

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function columnasDeEnvios(): array
{
    /** @var list<string> $columns */
    $columns = DB::table('information_schema.columns')
        ->where('table_name', 'weekly_summary_deliveries')
        ->orderBy('column_name')
        ->pluck('column_name')
        ->all();

    return $columns;
}

it('declara un resumen por cuenta y semana en la propia base de datos', function (): void {
    // LA INVARIANTE. Sin ella, dos pasadas simultaneas —un `cron` duplicado, una
    // ejecucion a mano encima de la programada— mandarian el mismo resumen dos
    // veces, y cada copia son nombres de la plantilla saliendo por SMTP.
    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $fila = [
        'manager_user_id' => $manager->id,
        'week_start' => '2026-09-14',
        'sent_at' => '2026-09-21 06:00:00+00',
        'employee_count' => 4,
        'row_count' => 4,
        'created_at' => '2026-09-21 06:00:00+00',
    ];

    DB::table('weekly_summary_deliveries')->insert($fila);

    // Dentro de una transaccion propia —un `SAVEPOINT` sobre la que abre
    // `RefreshDatabase`— porque en PostgreSQL una sentencia que falla aborta la
    // transaccion entera: sin el punto de retorno, la insercion legitima de
    // abajo no llegaria a ejecutarse.
    expect(fn (): mixed => DB::transaction(
        static fn (): bool => DB::table('weekly_summary_deliveries')->insert($fila),
    ))->toThrow(QueryException::class);

    // Y la semana siguiente si entra: lo unico que se impide es repetir la
    // misma.
    DB::table('weekly_summary_deliveries')->insert([...$fila, 'week_start' => '2026-09-21']);

    expect(DB::table('weekly_summary_deliveries')->count())->toBe(2);
})->group('RF-PR-05');

it('guarda la semana como fecha civil y no como instante', function (): void {
    // `week_start` es `date`: «la semana del 14 de septiembre» no tiene hora ni
    // zona. Como `timestamptz` se habria movido de semana en cada conversion, y
    // el `UNIQUE` habria dejado de significar «una por semana».
    $tipo = DB::table('information_schema.columns')
        ->where('table_name', 'weekly_summary_deliveries')
        ->where('column_name', 'week_start')
        ->value('data_type');

    expect($tipo)->toBe('date');

    // El envio si es un instante, y por tanto `TIMESTAMPTZ` (regla dura 3).
    $sentAt = DB::table('information_schema.columns')
        ->where('table_name', 'weekly_summary_deliveries')
        ->where('column_name', 'sent_at')
        ->value('data_type');

    expect($sentAt)->toBe('timestamp with time zone');
})->group('RF-PR-05');

it('no guarda ningun dato personal de la plantilla', function (): void {
    // Regla dura 21 y minimizacion: de quien eran las horas que salieron por
    // correo consta en `audit_log`, que es donde RS-05 lo pide y donde tiene su
    // plazo de conservacion. Aqui solo hay una cuenta de gestion, una fecha y
    // dos recuentos — por eso esta tabla no entra en `RetentionScope`.
    expect(columnasDeEnvios())->toBe([
        'created_at',
        'employee_count',
        'id',
        'manager_user_id',
        'row_count',
        'sent_at',
        'week_start',
    ]);
})->group('RF-PR-05', 'RL-11');

it('la migracion se deshace y se vuelve a aplicar dejando la tabla igual', function (): void {
    // Con `migrate:rollback` y `migrate` sobre la conexion de MIGRACION, que es
    // exactamente lo que hace `update.sh` cuando algo falla y hay que regresar.
    // No se invoca `up()`/`down()` a mano: ademas de probar otra cosa, el rol de
    // la aplicacion **no es propietario** de la tabla (ADR-033) y ni siquiera
    // podria soltarla.
    //
    // **La restauracion va en `finally`.** La base de pruebas se comparte dentro
    // de una ejecucion, y dejarla sin la tabla por un fallo de asercion
    // convertiria un fallo en una cascada de todo lo que viniera despues.
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $antes = columnasDeEnvios();

    try {
        $steps = pasosHastaLosEnviosSemanales($migrator);

        expect($steps)->toBeGreaterThan(0);

        [$exitCode] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);

        expect($exitCode)->toBe(0)
            ->and($migrator->getSchemaBuilder()->hasTable('weekly_summary_deliveries'))->toBeFalse();

        [$forward] = Commands::run('migrate --database='.$name);

        expect($forward)->toBe(0);
    } finally {
        // Idempotente: si el `migrate` de arriba ya corrio, este no hace nada.
        Commands::run('migrate --database='.$name);
    }

    expect($migrator->getSchemaBuilder()->hasTable('weekly_summary_deliveries'))->toBeTrue()
        ->and(columnasDeEnvios())->toBe($antes)
        // Y con su invariante puesta: un `down()`/`up()` que perdiera el indice
        // unico dejaria una instalacion revertida capaz de mandar el resumen dos
        // veces, sin que nada fallara.
        ->and($migrator->table('pg_indexes')
            ->where('tablename', 'weekly_summary_deliveries')
            ->pluck('indexname')
            ->all())->toContain('weekly_summary_deliveries_unique');
})->group('RF-PR-05');

/**
 * Cuantos pasos de `migrate:rollback` hacen falta para deshacer la de esta
 * tarea, contando desde la ultima aplicada.
 *
 * Existe para que la prueba no dependa de cuantas migraciones se añadan
 * despues: con un `--step=1` escrito a mano, la siguiente tarea que traiga una
 * migracion haria que esto revirtiera la suya y diera por probado un `down()`
 * que no es el que se quiere comprobar.
 */
function pasosHastaLosEnviosSemanales(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')
        ->orderByDesc('id')
        ->pluck('migration')
        ->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === '2026_09_23_100000_weekly_summary_deliveries') {
            return $steps;
        }
    }

    return 0;
}
