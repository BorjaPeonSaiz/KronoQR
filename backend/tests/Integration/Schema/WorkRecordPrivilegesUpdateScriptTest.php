<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;
use Tests\Support\Database\RefreshDatabase;

/*
 * `update.sh` y las migraciones del bloque 6 dicen lo mismo (ADR-057 §1 y §7,
 * tarea 6.4; RL-04, RS-07).
 *
 * `work_record_privileges_sql` lleva copiada la lista de privilegios que dejan
 * `2026_10_13_100000_restrict_application_updates_on_work_record` y
 * `2026_10_13_100100_restrict_application_writes_on_compliance_profiles`, y la
 * compara por conjunto exacto tras cada actualizacion. Si una migracion
 * posterior concede o retira una columna y nadie toca el script, cada
 * actualizacion «repararia» los privilegios hacia el estado viejo: el fichaje
 * romperia con `42501` o el runtime recuperaria lo que no debe. El job de
 * actualizacion de la CI no corre en cada push; esta prueba si.
 *
 * Se carga el SQL tal como lo genera el script —`source` de `update.sh`, que
 * acaba con la guarda de `install.sh`, igual que hace `UpdateScriptTest`, y
 * carga la funcion de `lib/work-record-privileges.sh`, que comparte con
 * `doctor.sh`— y se ejecuta contra la base de pruebas migrada con el rol de
 * migracion, que es como lo lanza `psql_migrator`.
 */

uses(RefreshDatabase::class);

function privilegiosActualizadorSql(string $role): string
{
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
            .'work_record_privileges_sql '.escapeshellarg($role)
        ),
        timeout: 60.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $process->getOutput();
}

it('el SQL de update.sh da ok contra el esquema migrado', function (): void {
    $sql = privilegiosActualizadorSql(Config::string('database.roles.application'));

    expect($sql)->toContain('has_table_privilege');

    $row = DB::connection(config()->string('database.migrations.connection'))->selectOne($sql);

    \assert(is_object($row));

    $values = array_values((array) $row);

    expect($values)->toHaveCount(1)
        ->and($values[0])->toBe('ok');
})->group('RS-07', 'RL-04');

it('el SQL de update.sh detecta la guarda de la zona del centro tocada, y la da por buena sin su migracion', function (string $statement, string $expected): void {
    // RN-23, bloque 2 de la 2.3.0: el trigger de `sites` y la funcion de la
    // correccion de consola. En una transaccion del rol de migracion que se
    // deshace, como la prueba de abajo.
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $sql = privilegiosActualizadorSql(Config::string('database.roles.application'));

    $migrator->beginTransaction();

    try {
        $migrator->statement($statement);
        $row = $migrator->selectOne($sql);

        $migrator->table('migrations')->where('migration', '2026_10_18_120000_guard_site_timezone_in_database')->delete();
        $without = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row) && is_object($without));

    expect(array_values((array) $row)[0])->toBe($expected)
        ->and(array_values((array) $without)[0])->toBe('ok');
})->with([
    'trigger desactivado' => ['ALTER TABLE sites DISABLE TRIGGER sites_guard_timezone', 'trigger:sites_guard_timezone:DESACTIVADO'],
    'trigger borrado' => ['DROP TRIGGER sites_guard_timezone ON sites', 'trigger:sites_guard_timezone:AUSENTE'],
    'funcion sin SECURITY DEFINER' => ['ALTER FUNCTION public.site_correct_timezone(bigint, text, text) SECURITY INVOKER', 'site_correct_timezone:DEFINER'],
    'funcion para PUBLIC' => ['GRANT EXECUTE ON FUNCTION public.site_correct_timezone(bigint, text, text) TO PUBLIC', 'site_correct_timezone:PUBLIC'],
    'funcion borrada' => ['DROP FUNCTION public.site_correct_timezone(bigint, text, text)', 'site_correct_timezone:AUSENTE'],
])->group('RS-07', 'RN-23');

it('el SQL de update.sh detecta un privilegio que sobra', function (): void {
    // La otra mitad: un `ok` incondicional tambien pasaria la prueba anterior.
    // El GRANT va dentro de una transaccion del rol de migracion que se
    // deshace, y la consulta corre en esa misma sesion.
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $application = Config::string('database.roles.application');
    $sql = privilegiosActualizadorSql($application);

    $migrator->beginTransaction();

    try {
        $migrator->statement('GRANT UPDATE ON TABLE scan_events TO "'.$application.'"');
        $row = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row));

    expect(array_values((array) $row)[0])->toBeString()->toContain('scan_events:UPDATE');
})->group('RS-07', 'RL-04');
