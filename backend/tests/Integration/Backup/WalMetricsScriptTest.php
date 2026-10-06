<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * La medida continua del RPO: `infra/scripts/wal-metrics.sh`, que lanza
 * `php artisan backup:wal-metrics` cada minuto desde el `scheduler` (2.2.0,
 * bloque 20: R5-DV-01; condicion C17 y BAJO de `metrics/` del dictamen de
 * seguridad; RNF-D-02, RL-12).
 *
 * Dos mitades:
 *
 *   · EL CALCULO, con un `psql` falso en el PATH que devuelve un estado del
 *     archivado escrito a mano: cuanto WAL esta sin archivar, desde cuando, y
 *     que un fichero de estado manipulado (`metrics/` lo escriben tambien `app`
 *     y `horizon`) no ejecuta nada y se reinicia.
 *   · LAS CONSULTAS, contra el PostgreSQL de verdad y con un rol recien creado
 *     SIN NINGUN PRIVILEGIO (ni `pg_monitor`, ni `pg_read_all_data`): si las
 *     consultas necesitaran algo mas, la medida fallaria en produccion con el
 *     rol `fichaje_backup` y la alerta `MedicionDeWalAusente` sonaria siempre.
 */

/** 16 MiB: el tamaño de segmento de PostgreSQL por defecto. */
const WAL_METRICS_SEGMENT = 16777216;

/**
 * Un `psql` falso: a la consulta del archivado le contesta `STUB_ARCHIVER` y a
 * la de los slots `STUB_SLOTS`. Con `STUB_FAIL=1` no contesta, como una base
 * caida. Deja constancia de cada llamada para comprobar que no recibe claves.
 */
const WAL_METRICS_PSQL_FALSO = <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"${STUB_DIR}/psql.log"
if [ "${STUB_FAIL:-0}" = 1 ]; then
  echo "psql: could not connect to server" >&2
  exit 2
fi
case "$*" in
*pg_stat_archiver*) printf '%s\n' "${STUB_ARCHIVER}" ;;
*pg_replication_slots*) printf '%s\n' "${STUB_SLOTS:-0|0}" ;;
*) exit 3 ;;
esac
BASH;

/**
 * Un `BACKUP_PATH` temporal con `metrics/` y el `psql` falso.
 *
 * @return array{dir: string, metrics: string, bin: string}
 */
function walMetricsSandbox(): array
{
    $dir = sys_get_temp_dir().'/kq-wal-metrics-'.bin2hex(random_bytes(4));
    mkdir($dir.'/backups/metrics', 0o750, true);
    mkdir($dir.'/bin', 0o700, true);
    file_put_contents($dir.'/bin/psql', WAL_METRICS_PSQL_FALSO);
    chmod($dir.'/bin/psql', 0o755);

    return ['dir' => $dir, 'metrics' => $dir.'/backups/metrics', 'bin' => $dir.'/bin'];
}

/**
 * La fila que devuelve la consulta del archivado, en el orden del script.
 */
function walMetricsArchiverRow(
    string $lastArchived = '000000010000000000000003',
    int $lastArchivedAge = 120,
    int $archived = 3,
    int $failed = 0,
    int $failing = 0,
    string $insertLsn = '0/4000100',
    int $archiveTimeout = 900,
    int $activity = 10,
): string {
    return implode('|', [$lastArchived, $lastArchivedAge, $archived, $failed, $failing, $insertLsn, $archiveTimeout, WAL_METRICS_SEGMENT, $activity]);
}

/**
 * Ejecuta el script de verdad.
 *
 * @param  array{dir: string, metrics: string, bin: string}  $sandbox
 * @param  array<string, string>  $env
 */
function runWalMetrics(array $sandbox, array $env = [], bool $stub = true): Process
{
    $process = new Process(['bash', Repo::file('infra/scripts/wal-metrics.sh')], env: array_merge([
        'PATH' => ($stub ? $sandbox['bin'].':' : '').(string) getenv('PATH'),
        'STUB_DIR' => $sandbox['dir'],
        'BACKUP_ENV_FILE' => '/dev/null',
        'BACKUP_PATH' => $sandbox['dir'].'/backups',
        'KRONOQR_LANG' => 'es',
    ], $env), timeout: 60.0);
    $process->run();

    return $process;
}

/**
 * Las series de `kronoqr_wal.prom`, nombre => valor.
 *
 * @param  array{dir: string, metrics: string, bin: string}  $sandbox
 * @return array<string, string>
 */
function walMetricsSeries(array $sandbox): array
{
    $series = [];

    foreach (file($sandbox['metrics'].'/kronoqr_wal.prom', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^(kronoqr_wal_[a-z_]+) (-?\d+)$/', $line, $parts) === 1) {
            $series[$parts[1]] = $parts[2];
        }
    }

    return $series;
}

/**
 * @param  array{dir: string, metrics: string, bin: string}  $sandbox
 */
function walMetricsState(array $sandbox, string $contents): void
{
    file_put_contents($sandbox['metrics'].'/.wal-exporter.state', $contents);
}

// ---------------------------------------------------------------------------
// El calculo
// ---------------------------------------------------------------------------

it('publica las once series del WAL, con el estado del archivado tal cual', function (): void {
    $sandbox = walMetricsSandbox();

    $process = runWalMetrics($sandbox, [
        'STUB_ARCHIVER' => walMetricsArchiverRow(lastArchivedAge: 120, archived: 7, failed: 2, failing: 1, archiveTimeout: 900),
        'STUB_SLOTS' => '1|4096',
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $series = walMetricsSeries($sandbox);

    expect(array_keys($series))->toEqualCanonicalizing([
        'kronoqr_wal_unarchived_age_seconds', 'kronoqr_wal_unarchived_bytes', 'kronoqr_wal_unarchived_segments',
        'kronoqr_wal_last_archived_age_seconds', 'kronoqr_wal_archive_failing', 'kronoqr_wal_archive_failures_total',
        'kronoqr_wal_archived_total', 'kronoqr_wal_archive_timeout_seconds', 'kronoqr_wal_exporter_last_run_timestamp_seconds',
        'kronoqr_wal_replication_slots_inactive', 'kronoqr_wal_replication_slot_retained_bytes',
    ])
        ->and($series['kronoqr_wal_last_archived_age_seconds'])->toBe('120')
        ->and($series['kronoqr_wal_archived_total'])->toBe('7')
        ->and($series['kronoqr_wal_archive_failures_total'])->toBe('2')
        ->and($series['kronoqr_wal_archive_failing'])->toBe('1')
        ->and($series['kronoqr_wal_archive_timeout_seconds'])->toBe('900')
        ->and($series['kronoqr_wal_replication_slots_inactive'])->toBe('1')
        ->and($series['kronoqr_wal_replication_slot_retained_bytes'])->toBe('4096')
        ->and((int) $series['kronoqr_wal_exporter_last_run_timestamp_seconds'])->toBeGreaterThanOrEqual(time() - 60);

    // Atomico: ningun temporal a medio escribir junto al fichero que lee node-exporter.
    expect(glob($sandbox['metrics'].'/*.tmp') ?: [])->toBe([]);
})->group('RNF-D-02', 'RL-12');

it('mide los bytes y los segmentos completos sin archivar a partir del ultimo archivado', function (string $lsn, int $bytes, int $segments): void {
    // Ultimo archivado ...03: el archivo cubre hasta el final del segmento 3,
    // 4 x 16 MiB = 0x4000000.
    $sandbox = walMetricsSandbox();

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(insertLsn: $lsn)]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $series = walMetricsSeries($sandbox);

    expect((int) $series['kronoqr_wal_unarchived_bytes'])->toBe($bytes)
        ->and((int) $series['kronoqr_wal_unarchived_segments'])->toBe($segments);
})->with([
    'al dia' => ['0/4000000', 0, 0],
    'el segmento en curso' => ['0/4000100', 256, 0],
    // El atasco que progresa: la edad se reinicia en cada archivado y lo
    // taparia; tres segmentos completos sin archivar no.
    'tres segmentos atascados' => ['0/7000000', 3 * WAL_METRICS_SEGMENT, 3],
    'de otro identificador logico' => ['1/0', 0x100000000 - 0x4000000, intdiv(0x100000000 - 0x4000000, WAL_METRICS_SEGMENT)],
])->group('RNF-D-02');

it('sin ningun segmento archivado todavia mide el segmento en curso', function (): void {
    $sandbox = walMetricsSandbox();

    $process = runWalMetrics($sandbox, [
        'STUB_ARCHIVER' => walMetricsArchiverRow(lastArchived: '-', lastArchivedAge: -1, archived: 0, insertLsn: '0/1000200'),
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $series = walMetricsSeries($sandbox);

    expect($series['kronoqr_wal_last_archived_age_seconds'])->toBe('-1')
        ->and($series['kronoqr_wal_unarchived_bytes'])->toBe('512');
})->group('RNF-D-02');

it('la exposicion empieza con la primera escritura sin archivar y crece hasta que el archivo la cubre', function (): void {
    $sandbox = walMetricsSandbox();
    $insertion = 0x4000100;

    // Una escritura sin archivar desde hace 500 s: el estado lo dejo la muestra
    // anterior.
    walMetricsState($sandbox, "archived_wal=000000010000000000000003\ndirty_since=".(time() - 500)."\ndirty_lsn={$insertion}\nactivity=10\n");

    $pendiente = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(insertLsn: '0/4000100', activity: 10)]);
    $edad = (int) walMetricsSeries($sandbox)['kronoqr_wal_unarchived_age_seconds'];

    // El archivo avanza al segmento 4: cubre lo que estaba pendiente.
    $cubierto = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(lastArchived: '000000010000000000000004', insertLsn: '0/5000000', activity: 10)]);

    expect($pendiente->getExitCode())->toBe(0, $pendiente->getErrorOutput())
        ->and($edad)->toBeGreaterThanOrEqual(500)->toBeLessThan(560)
        ->and($cubierto->getExitCode())->toBe(0, $cubierto->getErrorOutput())
        ->and(walMetricsSeries($sandbox)['kronoqr_wal_unarchived_age_seconds'])->toBe('0');
})->group('RNF-D-02');

it('sin escrituras no hay exposicion, aunque queden unos bytes sin archivar', function (): void {
    // El falso positivo de la regla de la 2.1.0: un servidor inactivo de
    // madrugada tiene WAL «no importante» que archive_timeout no fuerza, y su
    // ultimo archivado envejece legitimamente.
    $sandbox = walMetricsSandbox();
    walMetricsState($sandbox, "archived_wal=000000010000000000000003\ndirty_since=0\ndirty_lsn=0\nactivity=10\n");

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(lastArchivedAge: 14400, insertLsn: '0/4000400', activity: 10)]);

    $series = walMetricsSeries($sandbox);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($series['kronoqr_wal_unarchived_age_seconds'])->toBe('0')
        ->and($series['kronoqr_wal_last_archived_age_seconds'])->toBe('14400');
})->group('RNF-D-02');

it('con escrituras nuevas y WAL sin archivar, la exposicion empieza a contar', function (): void {
    $sandbox = walMetricsSandbox();
    walMetricsState($sandbox, "archived_wal=000000010000000000000003\ndirty_since=0\ndirty_lsn=0\nactivity=10\n");

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(insertLsn: '0/4000400', activity: 11)]);

    $state = (string) file_get_contents($sandbox['metrics'].'/.wal-exporter.state');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($state)->toMatch('/^dirty_since=[1-9]\d*$/m')
        ->and($state)->toContain('dirty_lsn='.(0x4000400))
        ->and($state)->toContain('activity=11');
})->group('RNF-D-02');

it('tras una copia fisica (el ultimo archivado es el .backup) el segmento ya archivado sigue cubriendo la exposicion', function (): void {
    // Madrugada sin fichajes: la copia fisica deja como ultimo archivado el
    // `.backup`, que no dice hasta donde llega el WAL. Hay que acordarse del fin del
    // ultimo SEGMENTO real o la exposicion de antes de la copia no se cierra nunca y
    // `kronoqr_wal_unarchived_age_seconds` sube sin que haya dato en riesgo.
    $sandbox = walMetricsSandbox();
    $segmentEnd = 0x4000000;
    walMetricsState($sandbox, "archived_wal=000000010000000000000003\nlast_segment_end={$segmentEnd}\ndirty_since=".(time() - 500)."\ndirty_lsn=".(0x3000500)."\nactivity=10\n");

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(lastArchived: '000000010000000000000003.00000028.backup', insertLsn: '0/4000100', activity: 10)]);

    $series = walMetricsSeries($sandbox);
    $state = (string) file_get_contents($sandbox['metrics'].'/.wal-exporter.state');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($series['kronoqr_wal_unarchived_age_seconds'])->toBe('0')
        ->and($state)->toContain("last_segment_end={$segmentEnd}")
        ->and($state)->toContain('dirty_since=0');
})->group('RNF-D-02');

it('tras una copia fisica sin ningun estado anterior, el segmento que nombra el .backup cuenta como archivado', function (string $lastArchived): void {
    // Primera ejecucion justo despues de la copia fisica (o estado reiniciado): no hay
    // `last_segment_end` que recordar. El `.backup` (y un `.partial`) llevan el nombre de
    // un segmento que el archivador ya archivo antes que ellos: su fin es la cota. Sin
    // esto, ⑧ veia segmentos «pendientes» que nunca se limpiaban hasta el siguiente
    // segmento real, y una madrugada sin fichajes subiria el RPO sin dato en riesgo.
    $sandbox = walMetricsSandbox();

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(lastArchived: $lastArchived, insertLsn: '0/4000100', activity: 10)]);

    $series = walMetricsSeries($sandbox);
    $state = (string) file_get_contents($sandbox['metrics'].'/.wal-exporter.state');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($series['kronoqr_wal_unarchived_segments'])->toBe('0')
        ->and($series['kronoqr_wal_unarchived_bytes'])->toBe((string) 0x100)
        ->and($state)->toContain('last_segment_end='.(0x4000000));
})->with([
    'copia fisica' => '000000010000000000000003.00000028.backup',
    'segmento parcial (en curso: lo archivado llega hasta su inicio)' => '000000010000000000000004.partial',
])->group('RNF-D-02');

it('tras una copia fisica sin fichajes no sube la edad de lo no archivado, y un .history tampoco borra el fin conocido', function (string $lastArchived): void {
    $sandbox = walMetricsSandbox();
    walMetricsState($sandbox, "archived_wal=000000010000000000000003\nlast_segment_end=".(0x4000000)."\ndirty_since=0\ndirty_lsn=0\nactivity=10\n");

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(lastArchived: $lastArchived, lastArchivedAge: 14400, insertLsn: '0/4000100', activity: 10)]);

    $series = walMetricsSeries($sandbox);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($series['kronoqr_wal_unarchived_age_seconds'])->toBe('0')
        ->and($series['kronoqr_wal_unarchived_bytes'])->toBe((string) (0x4000100 - 0x4000000))
        ->and((string) file_get_contents($sandbox['metrics'].'/.wal-exporter.state'))->toContain('last_segment_end='.(0x4000000));
})->with([
    'copia fisica' => '000000010000000000000003.00000028.backup',
    'historia' => '00000002.history',
    'parcial' => '000000010000000000000004.partial',
])->group('RNF-D-02');

// ---------------------------------------------------------------------------
// El fichero de estado: lo pueden escribir app y horizon (BAJO del dictamen)
// ---------------------------------------------------------------------------

it('un estado manipulado no ejecuta nada y se reinicia con valores validos', function (): void {
    $sandbox = walMetricsSandbox();
    $canario = $sandbox['dir'].'/canario';

    walMetricsState($sandbox, implode("\n", [
        'archived_wal=../../etc/passwd',
        'dirty_since=$(touch '.$canario.')',
        'dirty_lsn=`touch '.$canario.'`',
        'activity=10; touch '.$canario,
        'eval touch '.$canario,
    ])."\n");

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(insertLsn: '0/4000400', activity: 11)]);

    $state = (string) file_get_contents($sandbox['metrics'].'/.wal-exporter.state');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_exists($canario))->toBeFalse()
        ->and(walMetricsSeries($sandbox)['kronoqr_wal_unarchived_age_seconds'])->toBe('0')
        // Se reescribe entero con lo que acaba de medir, en su forma cerrada.
        ->and($state)->toMatch('/\Aarchived_wal=[0-9A-F]{24}\nlast_segment_end=\d+\ndirty_since=\d+\ndirty_lsn=\d+\nactivity=\d+\n\z/');
})->group('RL-12', 'RNF-D-02');

it('un estado con basura o un dirty_since del futuro se ignora en vez de inventar una edad', function (string $contenido): void {
    $sandbox = walMetricsSandbox();
    walMetricsState($sandbox, $contenido);

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(insertLsn: '0/4000400', activity: 10)]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(walMetricsSeries($sandbox)['kronoqr_wal_unarchived_age_seconds'])->toBe('0');
})->with([
    'binario' => ["\x00\x01\x02\xff"],
    'numeros enormes' => ["dirty_since=99999999999999999999\ndirty_lsn=1\nactivity=10\n"],
    'del futuro' => ['dirty_since='.(time() + 86400)."\ndirty_lsn=1\nactivity=10\narchived_wal=000000010000000000000003\n"],
    'negativo' => ["dirty_since=-500\ndirty_lsn=1\nactivity=10\n"],
])->group('RL-12', 'RNF-D-02');

// ---------------------------------------------------------------------------
// Los fallos
// ---------------------------------------------------------------------------

it('sin base de datos sale con 2 y no toca el fichero publicado: el latido envejece y la alerta avisa', function (): void {
    $sandbox = walMetricsSandbox();
    file_put_contents($sandbox['metrics'].'/kronoqr_wal.prom', "kronoqr_wal_exporter_last_run_timestamp_seconds 1\n");

    $process = runWalMetrics($sandbox, ['STUB_FAIL' => '1']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('MedicionDeWalAusente')
        ->and((string) file_get_contents($sandbox['metrics'].'/kronoqr_wal.prom'))
        ->toBe("kronoqr_wal_exporter_last_run_timestamp_seconds 1\n");
})->group('RNF-D-02');

it('con una respuesta de PostgreSQL que no entiende sale con 2 en vez de publicar basura', function (string $fila): void {
    $sandbox = walMetricsSandbox();

    $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => $fila]);

    expect($process->getExitCode())->toBe(2)
        ->and(file_exists($sandbox['metrics'].'/kronoqr_wal.prom'))->toBeFalse();
})->with([
    'texto en un recuento' => [str_replace('|3|0|0|', '|tres|0|0|', walMetricsArchiverRow())],
    'LSN invalido' => [walMetricsArchiverRow(insertLsn: 'no-es-un-lsn')],
    'segmento de tamaño cero' => ['000000010000000000000003|120|3|0|0|0/4000100|900|0|10'],
])->group('RNF-D-02');

it('sin metrics/ escribible sale con 2 y dice como crearlo sin root', function (): void {
    $sandbox = walMetricsSandbox();
    chmod($sandbox['metrics'], 0o500);

    try {
        $process = runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow()]);
    } finally {
        chmod($sandbox['metrics'], 0o750);
    }

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('metrics');
})->group('RNF-D-02');

it('no pasa ninguna credencial por la linea de ordenes de psql', function (): void {
    $sandbox = walMetricsSandbox();

    runWalMetrics($sandbox, ['STUB_ARCHIVER' => walMetricsArchiverRow(), 'PGPASSWORD' => 'secreto-de-la-prueba']);

    expect((string) file_get_contents($sandbox['dir'].'/psql.log'))->not->toContain('secreto-de-la-prueba');
})->group('RS-08', 'RL-12');

// ---------------------------------------------------------------------------
// Las consultas, contra PostgreSQL y con un rol sin privilegios (C17)
// ---------------------------------------------------------------------------

it('mide con un rol de base de datos sin ningun privilegio: ni pg_monitor ni pg_read_all_data', function (): void {
    $migrator = DB::connection('pgsql_migrator');
    $role = 'kq_wal_probe_'.bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(16));
    $database = (string) $migrator->getDatabaseName();

    // Un rol de login recien creado, sin GRANT ninguno: lo unico que puede
    // hacer es lo que PostgreSQL concede a PUBLIC. CONNECT se da expresamente
    // por si la base lo hubiera retirado a PUBLIC; no es un privilegio de lectura.
    $migrator->statement("CREATE ROLE \"{$role}\" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION PASSWORD '{$password}'");
    $migrator->statement("GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$role}\"");

    try {
        $sandbox = walMetricsSandbox();

        $process = runWalMetrics($sandbox, [
            'PGHOST' => config()->string('database.connections.pgsql_migrator.host'),
            'PGPORT' => \is_scalar($port = config('database.connections.pgsql_migrator.port')) ? (string) $port : '5432',
            'PGDATABASE' => $database,
            'PGUSER' => $role,
            'PGPASSWORD' => $password,
        ], stub: false);

        $memberships = $migrator->scalar(
            'SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.member WHERE r.rolname = ?',
            [$role],
        );
        $timeout = $migrator->scalar("SELECT setting FROM pg_settings WHERE name = 'archive_timeout'");
    } finally {
        $migrator->statement("REVOKE CONNECT ON DATABASE \"{$database}\" FROM \"{$role}\"");
        $migrator->statement("DROP ROLE IF EXISTS \"{$role}\"");
    }

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

    $series = walMetricsSeries($sandbox);

    expect($memberships)->toBe(0)
        ->and($series)->toHaveCount(11)
        ->and($series['kronoqr_wal_archive_timeout_seconds'])->toBe($timeout)
        ->and((int) $series['kronoqr_wal_unarchived_age_seconds'])->toBeGreaterThanOrEqual(0)
        ->and((int) $series['kronoqr_wal_unarchived_segments'])->toBeGreaterThanOrEqual(0);
})->group('RNF-D-02', 'RL-12', 'RS-08');
