<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\ResanitizeErrorHistory;
use App\Modules\Product\Infrastructure\Persistence\DatabaseErrorEventRepository;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\SeededPersonalData;

/*
 * LAS FILAS ANTERIORES A LA 2.2.0, SANEADAS SOBRE POSTGRESQL (ADR-048 decision
 * 9, H6; RF-PD-15, RL-19).
 *
 * La unitaria fija las reglas de la fusion con un repositorio en memoria; aqui
 * se comprueba el SQL de verdad —recorrido por clave, reescritura, fusion con
 * borrado en una transaccion, el autor de la resolucion por `users.uuid`— y el
 * camino por el que se ejecuta al actualizar: la migracion de datos.
 */

uses(RefreshDatabase::class);

const RESANITIZE_ERROR_HISTORY_MIGRATION = '2026_10_03_100300_resanitize_error_history';

/**
 * Una fila como la habria escrito una version anterior a la 2.2.0: con el
 * nombre dentro y una huella calculada sobre el.
 *
 * @param  array<string, scalar>  $context
 */
function resanitizeErrorHistoryLegacyRow(
    ConnectionInterface $connection,
    string $message,
    array $context = [],
    int $occurrences = 1,
    string $firstSeen = '2026-09-01 10:00:00+00',
    string $lastSeen = '2026-09-02 10:00:00+00',
    ?string $resolvedAt = null,
    ?int $resolvedBy = null,
    string $appVersion = '2.1.0',
): int {
    return (int) $connection->table('error_events')->insertGetId([
        'fingerprint' => hash('sha256', 'legacy|'.$message),
        'level' => 'error',
        'source' => 'admin',
        'module' => null,
        'code' => 'web.vue_error',
        'message' => $message,
        'exception_class' => null,
        'file' => null,
        'line' => null,
        'context' => json_encode($context === [] ? new stdClass : $context),
        'trace_id' => null,
        'device_id' => null,
        'employee_uuid' => null,
        'app_version' => $appVersion,
        'occurrences' => $occurrences,
        'first_seen_at' => $firstSeen,
        'last_seen_at' => $lastSeen,
        'resolved_at' => $resolvedAt,
        'resolved_by_user_id' => $resolvedBy,
        'created_at' => $firstSeen,
        'updated_at' => $lastSeen,
    ]);
}

function resanitizeErrorHistoryRun(): void
{
    $connection = DB::connection();

    new ResanitizeErrorHistory(new DatabaseErrorEventRepository($connection, $connection), new NullLogger)->run();
}

it('deja las filas antiguas sin nombres y con huellas unicas, fundiendo las que coinciden', function (): void {
    $connection = DB::connection();
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    resanitizeErrorHistoryLegacyRow($connection, 'No se pudo fichar a Rosa Ficticiana', ['reason' => 'Luz Inventadez'], 3,
        '2026-09-02 10:00:00+00', '2026-09-03 10:00:00+00', '2026-09-04 10:00:00+00', $admin->id, 'Ficticiana');
    resanitizeErrorHistoryLegacyRow($connection, 'No se pudo fichar a Will Testerson', [], 4,
        '2026-09-01 10:00:00+00', '2026-09-05 10:00:00+00');
    resanitizeErrorHistoryLegacyRow($connection, 'TypeError: Cannot read properties of undefined', ['scope' => 'vue']);

    resanitizeErrorHistoryRun();

    $filas = $connection->table('error_events')->orderBy('id')->get();
    $volcado = (string) json_encode($filas->toArray(), JSON_UNESCAPED_UNICODE);

    expect(SeededPersonalData::allLeaksIn($volcado))->toBe([])
        ->and($filas)->toHaveCount(2)
        ->and($filas->pluck('fingerprint')->unique()->count())->toBe(2);

    $fundida = $filas->firstWhere('message', 'No se pudo fichar a …');

    if (! $fundida instanceof stdClass) {
        throw new RuntimeException('No ha quedado el grupo fundido.');
    }

    // Suma, el primero mas antiguo, el ultimo mas reciente, y ABIERTA: una de
    // las dos lo estaba.
    expect((int) $fundida->occurrences)->toBe(7)
        ->and((string) $fundida->first_seen_at)->toStartWith('2026-09-01 10:00:00')
        ->and((string) $fundida->last_seen_at)->toStartWith('2026-09-05 10:00:00')
        ->and($fundida->resolved_at)->toBeNull()
        ->and($fundida->resolved_by_user_id)->toBeNull();

    // El control positivo: el mensaje tecnico sale legible.
    expect($filas->pluck('message')->all())->toContain(SeededPersonalData::TECHNICAL);
})->group('RF-PD-15', 'RL-19');

it('si las dos estaban resueltas conserva la resolucion mas reciente con su autor', function (): void {
    $connection = DB::connection();
    $primero = ManagementUsers::withRole(UserRole::ADMIN);
    $segundo = ManagementUsers::withRole(UserRole::ADMIN);

    resanitizeErrorHistoryLegacyRow($connection, 'No se pudo fichar a Rosa Ficticiana', resolvedAt: '2026-09-04 10:00:00+00', resolvedBy: $primero->id);
    resanitizeErrorHistoryLegacyRow($connection, 'No se pudo fichar a Luz Inventadez', resolvedAt: '2026-09-06 10:00:00+00', resolvedBy: $segundo->id);

    resanitizeErrorHistoryRun();

    $fila = $connection->table('error_events')->sole();

    expect((int) $fila->resolved_by_user_id)->toBe($segundo->id)
        ->and((string) $fila->resolved_at)->toStartWith('2026-09-06 10:00:00');
})->group('RF-PD-15');

it('es idempotente: la segunda pasada no cambia ni una fila', function (): void {
    $connection = DB::connection();

    resanitizeErrorHistoryLegacyRow($connection, 'No se pudo fichar a Rosa Ficticiana');
    resanitizeErrorHistoryLegacyRow($connection, 'No se pudo fichar a Luz Inventadez');

    resanitizeErrorHistoryRun();
    $primera = $connection->table('error_events')->orderBy('id')->get()->toArray();

    resanitizeErrorHistoryRun();

    expect($connection->table('error_events')->orderBy('id')->get()->toArray())->toEqual($primera);
})->group('RF-PD-15', 'RL-19');

it('se ejecuta al migrar y su down() vacio deja revertir sin error', function (): void {
    /*
     * El camino de `update.sh`: `migrate:rollback` atraviesa la migracion de
     * datos —su `down()` no hace nada, y es la decision de ADR-048— y `migrate`
     * la vuelve a ejecutar sobre lo que haya. Las filas se siembran por la
     * conexion de MIGRACION, fuera de la transaccion de la prueba, porque es la
     * que usa la migracion; por eso se borran en el `finally`.
     */
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $ids = [];

    try {
        $ids[] = resanitizeErrorHistoryLegacyRow($migrator, 'No se pudo fichar a Rosa Ficticiana con 45678912K');
        $ids[] = resanitizeErrorHistoryLegacyRow($migrator, 'No se pudo fichar a Luz Inventadez con 45678912K');

        $steps = resanitizeErrorHistoryStepsBack($migrator);

        expect($steps)->toBeGreaterThan(0);

        [$back] = Commands::run('migrate:rollback --database='.$name.' --step='.$steps);
        expect($back)->toBe(0);

        // `down()` no ha tocado nada: las filas siguen como estaban.
        expect($migrator->table('error_events')->whereIn('id', $ids)->count())->toBe(2);

        [$forward] = Commands::run('migrate --database='.$name);
        expect($forward)->toBe(0);

        $filas = $migrator->table('error_events')->whereIn('id', $ids)->get();

        expect($filas)->toHaveCount(1)
            ->and(SeededPersonalData::allLeaksIn((string) json_encode($filas->toArray(), JSON_UNESCAPED_UNICODE)))->toBe([])
            ->and((int) $filas->first()?->occurrences)->toBe(2);
    } finally {
        Commands::run('migrate --database='.$name);
        $migrator->table('error_events')->whereIn('id', $ids)->delete();
    }
})->group('RF-PD-15', 'RL-19');

/**
 * Cuantos pasos de `migrate:rollback` hacen falta para deshacer esta migracion,
 * contando desde la ultima aplicada. Copia deliberada de la de
 * `BoundComplianceProfileThresholdsMigrationTest`: una funcion global de Pest
 * definida en otro fichero solo existe si ese fichero se ha cargado.
 */
function resanitizeErrorHistoryStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();

    $steps = 0;

    foreach ($applied as $name) {
        $steps++;

        if ($name === RESANITIZE_ERROR_HISTORY_MIGRATION) {
            return $steps;
        }
    }

    return 0;
}
