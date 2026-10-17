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

/**
 * Si `work_record_privileges_tampered` toma `$drift` por manipulacion (no se
 * repara: update.sh deshace y doctor.sh avisa) o por deriva reparable.
 */
function privilegiosActualizadorManipulado(string $drift): bool
{
    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
            .'if work_record_privileges_tampered '.escapeshellarg($drift).'; then echo si; else echo no; fi'
        ),
        timeout: 60.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return trim($process->getOutput()) === 'si';
}

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
        $migrator->statement(str_replace(':app', '"'.Config::string('database.roles.application').'"', $statement));
        $row = $migrator->selectOne($sql);

        $migrator->table('migrations')->where('migration', '2026_10_18_120000_guard_site_timezone_in_database')->delete();
        $without = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row) && is_object($without));

    expect(array_values((array) $row)[0])->toBe($expected)
        ->and(array_values((array) $without)[0])->toBe('ok')
        // Todo lo de la zona es manipulacion, no deriva que se repare sola,
        // salvo un EXECUTE de mas para PUBLIC, que update.sh si retira.
        ->and(privilegiosActualizadorManipulado($expected))->toBe(! str_ends_with($expected, ':PUBLIC'));
})->with([
    'traza escribible por la aplicacion' => ['GRANT INSERT ON TABLE site_timezone_changes TO :app', 'site_timezone_changes:INSERT'],
    'traza con la secuencia para la aplicacion' => ['GRANT USAGE ON SEQUENCE site_timezone_changes_id_seq TO :app', 'site_timezone_changes:SECUENCIA'],
    'traza sin lectura' => ['REVOKE SELECT ON TABLE site_timezone_changes FROM :app', 'site_timezone_changes:SELECT'],
    'traza borrada' => ['DROP TABLE site_timezone_changes', 'site_timezone_changes:AUSENTE'],
    'trigger desactivado' => ['ALTER TABLE sites DISABLE TRIGGER zz_sites_guard_timezone', 'trigger:zz_sites_guard_timezone:DESACTIVADO'],
    'trigger borrado' => ['DROP TRIGGER zz_sites_guard_timezone ON sites', 'trigger:zz_sites_guard_timezone:AUSENTE'],
    'funcion sin SECURITY DEFINER' => ['ALTER FUNCTION public.site_correct_timezone(bigint, text, text) SECURITY INVOKER', 'site_correct_timezone:DEFINER'],
    'funcion para PUBLIC' => ['GRANT EXECUTE ON FUNCTION public.site_correct_timezone(bigint, text, text) TO PUBLIC', 'site_correct_timezone:PUBLIC'],
    'funcion borrada' => ['DROP FUNCTION public.site_correct_timezone(bigint, text, text)', 'site_correct_timezone:AUSENTE'],
])->group('RS-07', 'RN-23');

it('toma por manipulacion la funcion de purga borrada, y no la repara', function (): void {
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $sql = privilegiosActualizadorSql(Config::string('database.roles.application'));

    $migrator->beginTransaction();

    try {
        $migrator->statement('DROP FUNCTION public.work_record_purge_batch(date, integer)');
        $row = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row));

    expect(array_values((array) $row)[0])->toBe('work_record_purge_batch:AUSENTE')
        ->and(privilegiosActualizadorManipulado('work_record_purge_batch:AUSENTE'))->toBeTrue()
        ->and(privilegiosActualizadorManipulado('site_correct_timezone:AUSENTE'))->toBeTrue()
        ->and(privilegiosActualizadorManipulado('scan_events:UPDATE'))->toBeFalse()
        ->and(privilegiosActualizadorManipulado('work_record_purge_batch:PUBLIC'))->toBeFalse();
})->group('RS-07', 'RL-04');

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
