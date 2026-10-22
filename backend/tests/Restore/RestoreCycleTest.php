<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * El ciclo REAL de copia y restauracion (RNF-D-05, RQ-09, RQ-13, RF-PR-04).
 *
 * Que es esto y por que esta aqui. La restauracion de una copia se ejecutaba
 * solo en `.github/workflows/backup-drill.yml` (pasos de shell), que
 * `qa:traceability` no lee: la matriz daba RNF-D-05 por cubierto con pruebas de
 * CONFIGURACION (que el workflow existe, que el cron es trimestral) y ninguna
 * que restaurase nada. Esta prueba ejecuta de verdad los dos scripts del
 * producto contra un PostgreSQL 17 real y deja constancia en la matriz como
 * cualquier otra: copia cifrada con `backup.sh`, restauracion en un contenedor
 * LIMPIO con `restore-drill.sh`, y la negativa (una copia alterada no restaura).
 *
 * Donde corre. En la suite `Restore`, que solo ejecuta el job `restore-cycle`
 * de `ci.yml` (en cada push, de noche y a mano): necesita un servidor, el
 * cliente de PostgreSQL 17 y Docker, y no cabe en las etapas 1-3. En cualquier
 * otro sitio (`make test` en el portatil) se omite de forma CONDICIONADA a
 * `KQ_RESTORE_CYCLE=1`, que el job pone junto con la conexion; no es un salto
 * incondicional, asi que la matriz la cuenta donde esa herramienta existe.
 *
 * El simulacro trimestral y completo (WAL, pitr, senales, roles) sigue siendo
 * `backup-drill.yml`; esto es el nucleo que la matriz tiene que poder leer.
 */

const RESTORE_CYCLE_ENCRYPTION_KEY = 'ciclo_de_restauracion_clave_0123456789';

function restoreCycleDisabled(): bool
{
    return getenv('KQ_RESTORE_CYCLE') !== '1';
}

function restoreCycleEnv(string $name): string
{
    $value = getenv($name);
    expect($value)->toBeString($name.' no esta definida: el job restore-cycle de ci.yml la exporta.');

    return (string) $value;
}

/**
 * Ejecuta un script de infra/scripts con la conexion del job y un destino de
 * copias propio. PGUSER y PGPASSWORD se quitan a proposito: backup-common.sh les
 * da prioridad sobre BACKUP_DB_*, y la copia se haria con el rol equivocado.
 *
 * @param  list<string>  $arguments
 */
function restoreCycleRun(string $script, array $arguments, string $backupPath): Process
{
    $process = new Process(
        ['bash', Repo::file('infra/scripts/'.$script), ...$arguments],
        env: [
            'BACKUP_ENV_FILE' => '/dev/null',
            'BACKUP_PATH' => $backupPath,
            'BACKUP_ENCRYPTION_KEY' => RESTORE_CYCLE_ENCRYPTION_KEY,
            'KRONOQR_LANG' => 'es',
            'PGUSER' => false,
            'PGPASSWORD' => false,
        ],
        timeout: 300.0,
    );
    $process->run();

    return $process;
}

/**
 * Siembra datos con relaciones, un turno nocturno y uno en el cambio de hora,
 * como el simulacro trimestral: si la copia se los comiera, los conteos lo dirian.
 */
function restoreCycleSeed(): void
{
    $sql = <<<'SQL'
        DROP TABLE IF EXISTS drill_shift_entries, drill_employees CASCADE;
        CREATE TABLE drill_employees (id int PRIMARY KEY, employee_code text NOT NULL UNIQUE);
        CREATE TABLE drill_shift_entries (
            id int PRIMARY KEY,
            employee_id int NOT NULL REFERENCES drill_employees(id),
            started_at timestamptz NOT NULL,
            ended_at timestamptz
        );
        INSERT INTO drill_employees SELECT g, 'EMP-' || g FROM generate_series(1, 200) g;
        INSERT INTO drill_shift_entries SELECT g, 1 + (g % 200),
            timestamptz '2026-01-15 22:00:00+00' - (g || ' hours')::interval,
            timestamptz '2026-01-16 06:00:00+00' - (g || ' hours')::interval
        FROM generate_series(1, 1000) g;
        INSERT INTO drill_shift_entries VALUES (99999, 1, '2026-03-29 01:30:00+01', '2026-03-29 09:00:00+02');
        SQL;

    $process = new Process(
        ['psql', '-h', restoreCycleEnv('DB_HOST'), '-p', restoreCycleEnv('DB_PORT'), '-U', restoreCycleEnv('DB_USERNAME'),
            '-d', restoreCycleEnv('DB_DATABASE'), '-v', 'ON_ERROR_STOP=1', '-q'],
        env: ['PGPASSWORD' => restoreCycleEnv('DB_PASSWORD')],
        input: $sql,
        timeout: 60.0,
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
}

/** Directorio de copias propio de la prueba, que se borra al terminar. */
function restoreCycleWorkspace(): string
{
    $path = sys_get_temp_dir().'/kq-restore-cycle-'.bin2hex(random_bytes(4));
    mkdir($path, 0o700, true);

    return $path;
}

function restoreCycleRemove(string $path): void
{
    (new Process(['rm', '-rf', $path]))->run();
}

/** La copia cifrada que acaba de hacer backup.sh. */
function restoreCycleLatestCopy(string $backupPath): string
{
    $copies = glob($backupPath.'/daily/*.dump.enc') ?: [];
    expect($copies)->not->toBeEmpty('backup.sh no ha dejado ninguna copia en '.$backupPath.'/daily.');

    sort($copies);

    return (string) end($copies);
}

it('restaura en un contenedor limpio la copia cifrada que hace backup.sh', function (): void {
    restoreCycleSeed();
    $backupPath = restoreCycleWorkspace();

    try {
        $backup = restoreCycleRun('backup.sh', ['run', '--mode', 'dump'], $backupPath);
        expect($backup->getExitCode())->toBe(0, $backup->getOutput().$backup->getErrorOutput());

        // Cifrada de verdad: ni la cabecera del volcado ni un nombre de columna en claro.
        $contents = (string) file_get_contents(restoreCycleLatestCopy($backupPath));
        expect($contents)->toStartWith('KQE1 ')
            ->and($contents)->not->toContain('PGDMP')
            ->and($contents)->not->toContain('employee_code');

        // El simulacro: contenedor limpio, integridad referencial y conteos del manifiesto.
        $drill = restoreCycleRun('restore-drill.sh', ['--skip-audit-chain', '--timeout', '120'], $backupPath);
        expect($drill->getExitCode())->toBe(0, $drill->getOutput().$drill->getErrorOutput());
    } finally {
        restoreCycleRemove($backupPath);
    }
})
    ->skip(restoreCycleDisabled(), 'Necesita PostgreSQL 17, su cliente y Docker: lo ejecuta el job restore-cycle de ci.yml (KQ_RESTORE_CYCLE=1).')
    ->group('RNF-D-05', 'RQ-09', 'RF-PR-04', 'RL-12');

it('rechaza con salida 6 una copia alterada: no se restaura lo que no se autentica', function (): void {
    restoreCycleSeed();
    $backupPath = restoreCycleWorkspace();

    try {
        $backup = restoreCycleRun('backup.sh', ['run', '--mode', 'dump'], $backupPath);
        expect($backup->getExitCode())->toBe(0, $backup->getOutput().$backup->getErrorOutput());

        // Un solo byte cambiado dentro de la copia: antes (AES-CBC sin MAC) restauraba basura.
        $handle = fopen(restoreCycleLatestCopy($backupPath), 'r+b');
        expect($handle)->not->toBeFalse();
        assert(is_resource($handle));
        fseek($handle, 200);
        fwrite($handle, 'basura');
        fclose($handle);

        $drill = restoreCycleRun('restore-drill.sh', ['--skip-audit-chain', '--timeout', '120'], $backupPath);
        expect($drill->getExitCode())->toBe(6, $drill->getOutput().$drill->getErrorOutput());
        // El 6 tambien saldria si la restauracion fallara despues de abrir la copia:
        // el motivo tiene que ser que NO supera la comprobacion de autenticidad.
        expect($drill->getOutput().$drill->getErrorOutput())->toContain('NO supera la comprobacion de autenticidad');
    } finally {
        restoreCycleRemove($backupPath);
    }
})
    ->skip(restoreCycleDisabled(), 'Necesita PostgreSQL 17, su cliente y Docker: lo ejecuta el job restore-cycle de ci.yml (KQ_RESTORE_CYCLE=1).')
    ->group('RNF-D-05', 'RQ-09', 'RL-12');
