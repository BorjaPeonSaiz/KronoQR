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

it('el SQL de update.sh detecta la guarda de la zona del centro tocada, y la da por buena sin su migracion', function (string $statement, string $expected, bool $tampered): void {
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
        // Manipulacion (no se repara: update.sh deshace) o deriva que se repara.
        ->and(privilegiosActualizadorManipulado($expected))->toBe($tampered);
})->with([
    'traza escribible por la aplicacion' => ['GRANT INSERT ON TABLE site_timezone_changes TO :app', 'site_timezone_changes:INSERT', false],
    'traza con la secuencia para la aplicacion' => ['GRANT USAGE ON SEQUENCE site_timezone_changes_id_seq TO :app', 'site_timezone_changes:SECUENCIA', false],
    'traza sin lectura' => ['REVOKE SELECT ON TABLE site_timezone_changes FROM :app', 'site_timezone_changes:SELECT', false],
    'traza borrada' => ['DROP TABLE site_timezone_changes', 'site_timezone_changes:AUSENTE', true],
    'linea base con INSERT para la aplicacion' => ['GRANT INSERT ON TABLE site_timezone_audit_baseline TO :app', 'site_timezone_audit_baseline:INSERT', false],
    'linea base reescribible por la aplicacion' => ['GRANT UPDATE ON TABLE site_timezone_audit_baseline TO :app', 'site_timezone_audit_baseline:UPDATE', true],
    'traza borrable por la aplicacion' => ['GRANT DELETE ON TABLE site_timezone_changes TO :app', 'site_timezone_changes:DELETE', true],
    'traza vaciable por la aplicacion' => ['GRANT TRUNCATE ON TABLE site_timezone_changes TO :app', 'site_timezone_changes:TRUNCATE', true],
    'linea base borrada' => ['DROP TABLE site_timezone_audit_baseline', 'site_timezone_audit_baseline:AUSENTE', true],
    'trigger desactivado' => ['ALTER TABLE sites DISABLE TRIGGER zz_sites_guard_timezone', 'trigger:zz_sites_guard_timezone:DESACTIVADO', true],
    'trigger borrado' => ['DROP TRIGGER zz_sites_guard_timezone ON sites', 'trigger:zz_sites_guard_timezone:AUSENTE', true],
    'funcion sin SECURITY DEFINER' => ['ALTER FUNCTION public.site_correct_timezone(bigint, text, text) SECURITY INVOKER', 'site_correct_timezone:DEFINER', true],
    'funcion para PUBLIC' => ['GRANT EXECUTE ON FUNCTION public.site_correct_timezone(bigint, text, text) TO PUBLIC', 'site_correct_timezone:PUBLIC', false],
    'funcion borrada' => ['DROP FUNCTION public.site_correct_timezone(bigint, text, text)', 'site_correct_timezone:AUSENTE', true],
])->group('RS-07', 'RN-23');

it('el SQL de update.sh detecta un trigger de la duracion borrado, y no lo exige sin su migracion', function (string $trigger): void {
    // RN-06, RN-09, ADR-057 §2 (2.3.0): una 2.2.x no tiene la migracion del
    // 25-10 hasta que migra. Con el rol de migracion, en una transaccion que se
    // deshace.
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $sql = privilegiosActualizadorSql(Config::string('database.roles.application'));

    $migrator->beginTransaction();

    try {
        $migrator->statement('DROP TRIGGER '.$trigger.' ON shift_entries');
        $row = $migrator->selectOne($sql);

        $migrator->table('migrations')->where('migration', '2026_10_25_100000_guard_shift_entry_duration_in_database')->delete();
        $without = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row) && is_object($without));

    $expected = 'trigger:'.$trigger.':AUSENTE';

    expect(array_values((array) $row)[0])->toBe($expected)
        ->and(array_values((array) $without)[0])->toBe('ok')
        // Un trigger quitado lo quita un superusuario: update.sh deshace.
        ->and(privilegiosActualizadorManipulado($expected))->toBeTrue();
})->with([
    'el del alta' => ['shift_entries_guard_duration_insert'],
    'el del cierre' => ['shift_entries_guard_duration_close'],
])->group('RS-07', 'RL-04', 'RN-06');

it('la reparacion de update.sh deja la traza y la linea base de la zona en solo lectura', function (): void {
    // Privilegios de mas sobre la traza, su secuencia y la linea base, y el
    // SELECT de la traza retirado: es deriva, y la reparacion la deshace. En
    // una transaccion del rol de migracion que se deshace.
    $application = Config::string('database.roles.application');
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $sql = privilegiosActualizadorSql($application);

    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
            .'CFG_DB_USERNAME='.escapeshellarg($application).'; KQ_WORK_RECORD_STAGE=b7; work_record_privileges_repair_sql'
        ),
        timeout: 60.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $migrator->beginTransaction();

    try {
        foreach ([
            'GRANT INSERT ON TABLE site_timezone_changes TO "'.$application.'"',
            'REVOKE SELECT ON TABLE site_timezone_changes FROM "'.$application.'"',
            'GRANT USAGE ON SEQUENCE site_timezone_changes_id_seq TO "'.$application.'"',
            'GRANT INSERT ON TABLE site_timezone_audit_baseline TO "'.$application.'"',
        ] as $statement) {
            $migrator->statement($statement);
        }

        $before = $migrator->selectOne($sql);
        $migrator->getPdo()->exec($process->getOutput());
        $after = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($before) && is_object($after));

    expect(array_values((array) $before)[0])->toBe('site_timezone_audit_baseline:INSERT,site_timezone_changes:INSERT,site_timezone_changes:SECUENCIA,site_timezone_changes:SELECT')
        ->and(array_values((array) $after)[0])->toBe('ok');
})->group('RS-07', 'RN-23');

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

it('vigila la minimizacion en sitio: trigger desactivado, DELETE devuelto y funcion de trigger ejecutable', function (): void {
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $application = Config::string('database.roles.application');
    $sql = privilegiosActualizadorSql($application);

    $migrator->beginTransaction();

    try {
        $migrator->statement('ALTER TABLE employees DISABLE TRIGGER employees_guard_anonymized');
        $migrator->statement('ALTER TABLE absences DISABLE TRIGGER absences_guard_anonymized_employee');
        $migrator->statement('GRANT EXECUTE ON FUNCTION public.effective_compliance_profile_ids() TO "'.$application.'"');
        $migrator->statement('GRANT DELETE ON TABLE absences TO "'.$application.'"');
        $migrator->statement('GRANT DELETE ON TABLE employees TO "'.$application.'"');
        $migrator->statement('GRANT DELETE ON TABLE users TO "'.$application.'"');
        $migrator->statement('GRANT EXECUTE ON FUNCTION public.users_guard_anonymized() TO "'.$application.'"');
        $row = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row));
    $drift = (string) array_values((array) $row)[0];

    expect($drift)->toContain('trigger:employees_guard_anonymized:DESACTIVADO')
        ->toContain('absences:DELETE')
        ->toContain('trigger:absences_guard_anonymized_employee:DESACTIVADO')
        ->toContain('effective_compliance_profile_ids:EJECUTABLE')
        ->toContain('employees:DELETE')
        ->toContain('users:DELETE')
        ->toContain('users_guard_anonymized:EJECUTABLE')
        ->and(privilegiosActualizadorManipulado($drift))->toBeTrue()
        ->and(privilegiosActualizadorManipulado('absences:DELETE,users_guard_anonymized:EJECUTABLE'))->toBeFalse()
        ->and(privilegiosActualizadorManipulado('check:users_chk_anonymized_is_minimised:SIN_VALIDAR'))->toBeTrue();
})->group('RS-07', 'RL-04');

it('el SQL de update.sh exige la reapertura de RN-19 con su migracion, y no sin ella', function (string $statement, string $expected, bool $tampered, string $expectedWithout): void {
    // Enmienda del 27-10-2026 de ADR-057 §2: la funcion del trigger que admite
    // la reapertura y `UPDATE (context)`. Con el rol de migracion, en una
    // transaccion que se deshace.
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $sql = privilegiosActualizadorSql(Config::string('database.roles.application'));

    $migrator->beginTransaction();

    try {
        $migrator->statement(str_replace(':app', '"'.Config::string('database.roles.application').'"', $statement));
        $row = $migrator->selectOne($sql);

        $migrator->table('migrations')->where('migration', '2026_10_27_100100_allow_system_reopening_of_rejected_pin_scan')->delete();
        $without = $migrator->selectOne($sql);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($row) && is_object($without));

    expect(array_values((array) $row)[0])->toBe($expected)
        // Sin la migracion, la funcion anterior y sin `context` es lo esperado:
        // un `context` concedido sobra, y se repara.
        ->and(array_values((array) $without)[0])->toBe($expectedWithout)
        ->and(privilegiosActualizadorManipulado($expected))->toBe($tampered);
})->with([
    'la funcion sin reapertura' => [
        'CREATE OR REPLACE FUNCTION public.incidents_guard_transition() RETURNS trigger LANGUAGE plpgsql '
            .'SET search_path = pg_catalog, pg_temp AS $kq$ BEGIN RETURN NEW; END $kq$',
        'trigger:incidents_guard_transition:SIN_REAPERTURA',
        true,
        'incidents.context:UPDATE',
    ],
    'sin UPDATE de context' => ['REVOKE UPDATE (context) ON TABLE incidents FROM :app', 'incidents.context:UPDATE', false, 'ok'],
])->group('RS-07', 'RL-04', 'RN-19');

it('la reparacion de update.sh concede UPDATE (context) solo con la migracion de la reapertura', function (): void {
    $application = Config::string('database.roles.application');
    $migrator = DB::connection(config()->string('database.migrations.connection'));
    $sql = privilegiosActualizadorSql($application);

    $process = Process::fromShellCommandline(
        'bash -c '.escapeshellarg(
            'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
            .'CFG_DB_USERNAME='.escapeshellarg($application).'; KQ_WORK_RECORD_STAGE=b7; work_record_privileges_repair_sql'
        ),
        timeout: 60.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $migrator->beginTransaction();

    try {
        $migrator->statement('REVOKE UPDATE (context) ON TABLE incidents FROM "'.$application.'"');
        $before = $migrator->selectOne($sql);
        $migrator->getPdo()->exec($process->getOutput());
        $after = $migrator->selectOne($sql);

        // Sin la migracion, la reparacion no la concede.
        $migrator->table('migrations')->where('migration', '2026_10_27_100100_allow_system_reopening_of_rejected_pin_scan')->delete();
        $migrator->getPdo()->exec($process->getOutput());
        /** @var object{granted: bool} $granted */
        $granted = $migrator->selectOne("SELECT has_column_privilege(?, 'incidents', 'context', 'UPDATE') AS granted", [$application]);
    } finally {
        $migrator->rollBack();
    }

    \assert(is_object($before) && is_object($after));

    expect(array_values((array) $before)[0])->toBe('incidents.context:UPDATE')
        ->and(array_values((array) $after)[0])->toBe('ok')
        ->and($granted->granted)->toBeFalse();
})->group('RS-07', 'RL-04', 'RN-19');
