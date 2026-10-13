<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;

/*
 * La migracion del suelo legal de la retencion sobre datos (RL-02, RNF-D-04,
 * `/migracion-segura`): con un perfil español a tres años se niega, nombra el
 * perfil y no deja nada a medias; con cuatro crea las dos restricciones y las
 * deja VALIDADAS; y su `down()` las quita sin tocar datos.
 *
 * Por `migrate:rollback` y `migrate` sobre la conexion de MIGRACION, que es el
 * camino de `update.sh`, y leyendo por esa misma conexion para no pelear por
 * el bloqueo con la transaccion abierta de `RefreshDatabase`. Lo que se cambia
 * con ella se confirma: el `finally` deja la base de pruebas, que es
 * compartida, como estaba.
 */

uses(RefreshDatabase::class);

const RETENTION_FLOOR_MIGRATION = '2026_10_10_100000_add_retention_floor_checks_to_compliance_profiles';

const RETENTION_FLOOR_MIGRATION_CHECKS = [
    'compliance_profiles_chk_jurisdiction_format',
    'compliance_profiles_chk_retention_floor_es',
];

/**
 * Las restricciones de la migracion presentes y si estan validadas.
 *
 * @return array<string, bool>
 */
function retentionFloorMigrationChecks(ConnectionInterface $connection): array
{
    $checks = [];

    foreach ($connection->table('pg_constraint')
        ->where('conrelid', DB::raw("'compliance_profiles'::regclass"))
        ->whereIn('conname', RETENTION_FLOOR_MIGRATION_CHECKS)
        ->orderBy('conname')
        ->get(['conname', 'convalidated']) as $row) {
        /** @var object{conname: string, convalidated: bool} $row */
        $checks[$row->conname] = $row->convalidated;
    }

    return $checks;
}

/**
 * `migrate` que se niega: en pruebas, Artisan relanza la excepcion de la
 * migracion en vez de devolver el codigo de salida. Se recoge el mensaje, que
 * es lo que vera quien actualiza.
 *
 * @return array{0: int, 1: string}
 */
function retentionFloorMigrationMigrate(string $connection): array
{
    try {
        return Commands::run('migrate --database='.$connection);
    } catch (RuntimeException $refused) {
        return [1, $refused->getMessage()];
    }
}

/** Pasos de `migrate:rollback` hasta deshacer esta migracion, desde la ultima aplicada. */
function retentionFloorMigrationStepsBack(ConnectionInterface $connection): int
{
    /** @var list<string> $applied */
    $applied = $connection->table('migrations')->orderByDesc('id')->pluck('migration')->all();
    $position = array_search(RETENTION_FLOOR_MIGRATION, $applied, true);

    return $position === false ? 0 : $position + 1;
}

it('se niega con un perfil español a tres años, nombrandolo, y sin dejar restricciones a medias', function (): void {
    $name = config()->string('database.migrations.connection');
    $migrator = DB::connection($name);
    $id = $migrator->table('compliance_profiles')->where('name', 'ES-hosteleria')->value('id');
    $profile = is_numeric($id) ? (int) $id : 0;

    expect(retentionFloorMigrationChecks($migrator))->toBe([
        'compliance_profiles_chk_jurisdiction_format' => true,
        'compliance_profiles_chk_retention_floor_es' => true,
    ]);

    try {
        $steps = retentionFloorMigrationStepsBack($migrator);

        expect($steps)->toBeGreaterThan(0);
        expect(Commands::run('migrate:rollback --database='.$name.' --step='.$steps)[0])->toBe(0);

        // `down()` las quita y no toca el perfil.
        expect(retentionFloorMigrationChecks($migrator))->toBe([])
            ->and($migrator->table('compliance_profiles')->where('id', $profile)->value('retention_years'))->toBe(4);

        // Una instalacion que bajo la retencion a tres años antes de actualizar.
        $migrator->table('compliance_profiles')->where('id', $profile)->update(['retention_years' => 3]);

        [$refused, $output] = retentionFloorMigrationMigrate($name);

        expect($refused)->not->toBe(0)
            ->and($output)->toContain('el perfil #'.$profile.' (ES) conserva 3 años y el suelo legal de ES es 4')
            ->and($output)->toContain('No se ha cambiado nada')
            ->and(retentionFloorMigrationChecks($migrator))->toBe([])
            ->and($migrator->table('compliance_profiles')->where('id', $profile)->value('retention_years'))->toBe(3)
            ->and($migrator->table('migrations')->where('migration', RETENTION_FLOOR_MIGRATION)->exists())->toBeFalse();

        // Una jurisdiccion mal escrita tiene su propio mensaje.
        $migrator->table('compliance_profiles')->where('id', $profile)->update(['retention_years' => 4, 'jurisdiction' => 'es']);

        [$malformed, $malformedOutput] = retentionFloorMigrationMigrate($name);

        expect($malformed)->not->toBe(0)
            ->and($malformedOutput)->toContain('la jurisdicción «es» del perfil #'.$profile.' no tiene forma ISO 3166')
            ->and(retentionFloorMigrationChecks($migrator))->toBe([]);

        // Corregido el perfil, la migracion pasa y las deja validadas.
        $migrator->table('compliance_profiles')->where('id', $profile)->update(['jurisdiction' => 'ES']);

        expect(Commands::run('migrate --database='.$name)[0])->toBe(0)
            ->and(retentionFloorMigrationChecks($migrator))->toBe([
                'compliance_profiles_chk_jurisdiction_format' => true,
                'compliance_profiles_chk_retention_floor_es' => true,
            ]);

        // Y la vuelta atras, otra vez, las quita.
        expect(Commands::run('migrate:rollback --database='.$name.' --step='.retentionFloorMigrationStepsBack($migrator))[0])->toBe(0)
            ->and(retentionFloorMigrationChecks($migrator))->toBe([]);
    } finally {
        // La base de pruebas es compartida: perfil de serie y esquema al dia,
        // pase lo que pase arriba.
        $migrator->table('compliance_profiles')->where('id', $profile)->update(['jurisdiction' => 'ES', 'retention_years' => 4]);
        Commands::run('migrate --database='.$name);
    }

    expect(retentionFloorMigrationChecks($migrator))->toBe([
        'compliance_profiles_chk_jurisdiction_format' => true,
        'compliance_profiles_chk_retention_floor_es' => true,
    ]);
})->group('RL-02', 'RNF-D-04', 'RF-PD-07');
