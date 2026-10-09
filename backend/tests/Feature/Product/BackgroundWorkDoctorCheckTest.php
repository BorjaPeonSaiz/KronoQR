<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\HorizonQueueSupervisors;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\BackupProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\QueueProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\SchedulerProbe;
use App\Modules\Product\Infrastructure\Diagnostics\QueueSupervisors;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use App\Modules\Product\Infrastructure\Diagnostics\TextfileMetricsReader;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery\MockInterface;
use Tests\Architecture\Support\Repo;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Time\FixedClock;

/*
 * `product:doctor` frente a los procesos de fondo parados (RF-PD-13, RNF-D-02;
 * V3-PL-07 y V4-PL-3 de la verificacion final de la 2.2.0).
 *
 * Con el planificador parado y las copias sin hacerse, `doctor` salia en verde:
 * `permissions.backup_*` solo mira que las copias se puedan escribir, y
 * `queue.worker` decia «Hay alguien consumiendo la cola» con Horizon parado y la
 * cola vacia. Aqui se fija cada caso sobre ficheros `.prom` de verdad en un
 * directorio temporal, con el reloj fijado, y se comprueba que el umbral es el
 * mismo que el de la alerta que vigila la misma serie.
 */

const BACKGROUND_WORK_DOCTOR_CHECK_NOW = '2026-10-09 10:00:00';

afterEach(function (): void {
    GeneratedFilesSandbox::cleanUp();
});

function trabajoDeFondoInstante(string $wallClock): int
{
    return new DateTimeImmutable($wallClock, new DateTimeZone('UTC'))->getTimestamp();
}

/**
 * Un directorio de metricas con los `.prom` que se le pidan, con el formato
 * exacto que escriben `backup.sh` y `wal-metrics.sh`.
 *
 * @param  array<string, string>  $ficheros
 */
function trabajoDeFondoMetricas(array $ficheros = []): string
{
    $dir = GeneratedFilesSandbox::directory('metrics');

    foreach ($ficheros as $nombre => $contenido) {
        GeneratedFilesSandbox::file($dir.'/'.$nombre, $contenido);
    }

    return $dir;
}

function trabajoDeFondoCopia(int $resultado, string $tipo = 'dump'): string
{
    $exito = $resultado === 1 ? trabajoDeFondoInstante('2026-10-09 03:20:00') : 0;

    return "# HELP kronoqr_backup_last_result Resultado de la ultima copia: 1 correcta, 0 fallida.\n"
        ."# TYPE kronoqr_backup_last_result gauge\n"
        ."kronoqr_backup_last_result{type=\"{$tipo}\"} {$resultado}\n"
        ."# HELP kronoqr_backup_last_success_timestamp_seconds Momento de la ultima copia correcta.\n"
        ."# TYPE kronoqr_backup_last_success_timestamp_seconds gauge\n"
        ."kronoqr_backup_last_success_timestamp_seconds{type=\"{$tipo}\"} {$exito}\n";
}

function trabajoDeFondoVerificacion(int $resultado, int $momento): string
{
    $exito = $resultado === 1 ? $momento : 0;

    return "# HELP kronoqr_backup_last_verify_result Resultado de la ultima verificacion: 1 correcta, 0 fallida.\n"
        ."# TYPE kronoqr_backup_last_verify_result gauge\n"
        ."kronoqr_backup_last_verify_result {$resultado}\n"
        ."# HELP kronoqr_backup_last_verified_timestamp_seconds Momento de la ultima verificacion correcta.\n"
        ."# TYPE kronoqr_backup_last_verified_timestamp_seconds gauge\n"
        ."kronoqr_backup_last_verified_timestamp_seconds {$exito}\n";
}

function trabajoDeFondoLatido(int $momento): string
{
    return "# HELP kronoqr_wal_unarchived_age_seconds Edad.\n"
        ."# TYPE kronoqr_wal_unarchived_age_seconds gauge\n"
        ."kronoqr_wal_unarchived_age_seconds 0\n"
        ."# HELP kronoqr_wal_exporter_last_run_timestamp_seconds Momento de la ultima medida publicada.\n"
        ."# TYPE kronoqr_wal_exporter_last_run_timestamp_seconds gauge\n"
        ."kronoqr_wal_exporter_last_run_timestamp_seconds {$momento}\n";
}

function trabajoDeFondoSondaDeCopias(string $dir): DoctorFinding
{
    $findings = new BackupProbe(new TextfileMetricsReader($dir), FixedClock::at(BACKGROUND_WORK_DOCTOR_CHECK_NOW), '03:15')->run();

    expect($findings)->toHaveCount(1);

    return $findings[0];
}

function trabajoDeFondoSondaDelPlanificador(string $dir): DoctorFinding
{
    $findings = new SchedulerProbe(new TextfileMetricsReader($dir), FixedClock::at(BACKGROUND_WORK_DOCTOR_CHECK_NOW))->run();

    expect($findings)->toHaveCount(1);

    return $findings[0];
}

/** El texto que veria quien ejecuta `doctor`, en español. */
function trabajoDeFondoTexto(DoctorFinding $finding): string
{
    $text = __($finding->messageKey(), $finding->params, 'es');

    expect($text)->toBeString()->and($text)->not->toStartWith('doctor.checks.');

    return (string) $text;
}

function trabajoDeFondoArreglo(DoctorFinding $finding): string
{
    $key = $finding->fixKey();

    expect($key)->not->toBeNull();

    $text = __((string) $key, $finding->params, 'es');

    expect($text)->toBeString()->and($text)->not->toStartWith('doctor.fixes.');

    return (string) $text;
}

// --- backup.last_good_copy ---------------------------------------------------

it('da por buena una copia verificada esta madrugada y dice de cuando es', function (): void {
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas([
        BackupProbe::RUN_FILE => trabajoDeFondoCopia(1),
        BackupProbe::VERIFY_FILE => trabajoDeFondoVerificacion(1, trabajoDeFondoInstante('2026-10-09 03:21:00')),
    ]));

    expect($finding->id)->toBe('backup.last_good_copy')
        ->and($finding->status)->toBe(DoctorStatus::Ok)
        ->and(trabajoDeFondoTexto($finding))->toContain('2026-10-09 03:21 UTC')->toContain('hace 6 h');
})->group('RF-PD-13', 'RNF-D-02');

it('falla cuando la ultima copia verificada tiene mas de 26 horas', function (): void {
    // El caso de V3-PL-07: el planificador lleva dos noches parado, no hay
    // fichero nuevo y el ultimo dice «correcta». Solo la antiguedad lo delata.
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas([
        BackupProbe::RUN_FILE => trabajoDeFondoCopia(1),
        BackupProbe::VERIFY_FILE => trabajoDeFondoVerificacion(1, trabajoDeFondoInstante('2026-10-07 03:21:00')),
    ]));

    expect($finding->status)->toBe(DoctorStatus::Failure)
        ->and($finding->variant)->toBe('stale')
        ->and(trabajoDeFondoTexto($finding))->toContain('hace 54 horas')->toContain('03:15 UTC')
        ->and(trabajoDeFondoArreglo($finding))->toContain('docker compose exec scheduler php artisan backup:run');
})->group('RF-PD-13', 'RNF-D-02');

it('respeta el margen de la ventana nocturna: 25 horas todavia no es un fallo', function (): void {
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas([
        BackupProbe::VERIFY_FILE => trabajoDeFondoVerificacion(1, trabajoDeFondoInstante('2026-10-08 09:00:00')),
    ]));

    expect($finding->status)->toBe(DoctorStatus::Ok);
})->group('RF-PD-13', 'RNF-D-02');

it('falla cuando la ultima copia termino con error, aunque la verificacion anterior sea reciente', function (): void {
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas([
        BackupProbe::RUN_FILE => trabajoDeFondoCopia(0),
        BackupProbe::VERIFY_FILE => trabajoDeFondoVerificacion(1, trabajoDeFondoInstante('2026-10-09 03:21:00')),
    ]));

    expect($finding->status)->toBe(DoctorStatus::Failure)
        ->and($finding->variant)->toBe('last_failed')
        ->and(trabajoDeFondoArreglo($finding))->toContain('backup:run');
})->group('RF-PD-13', 'RNF-D-02');

it('falla cuando la ultima verificacion no paso', function (): void {
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas([
        BackupProbe::RUN_FILE => trabajoDeFondoCopia(1),
        BackupProbe::VERIFY_FILE => trabajoDeFondoVerificacion(0, trabajoDeFondoInstante('2026-10-09 03:21:00')),
    ]));

    expect($finding->status)->toBe(DoctorStatus::Failure)
        ->and($finding->variant)->toBe('verify_failed')
        ->and(trabajoDeFondoArreglo($finding))->toContain('backup:verify');
})->group('RF-PD-13', 'RNF-D-02');

it('sin ningun resultado de copia avisa y no falla: es lo normal antes de la primera noche', function (): void {
    // `install.sh` se para con un `2`. Una instalacion recien hecha no tiene
    // copia y no puede ser un fallo.
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas());

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('never')
        ->and(trabajoDeFondoTexto($finding))->toContain('03:15 UTC');
})->group('RF-PD-13', 'RNF-D-02');

it('avisa sin reventar si el fichero de resultado no se puede leer', function (): void {
    $dir = trabajoDeFondoMetricas([
        BackupProbe::VERIFY_FILE => trabajoDeFondoVerificacion(1, trabajoDeFondoInstante('2026-10-09 03:21:00')),
    ]);
    chmod($dir.'/'.BackupProbe::VERIFY_FILE, 0o000);

    try {
        $finding = trabajoDeFondoSondaDeCopias($dir);
    } finally {
        chmod($dir.'/'.BackupProbe::VERIFY_FILE, 0o600);
    }

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('unknown');
})->group('RF-PD-13', 'RNF-D-02');

it('ignora lo que no es un numero en el fichero en lugar de interpretarlo', function (): void {
    // `metrics/` lo escriben tambien `app` y `horizon`: el contenido es
    // entrada no confiable.
    $finding = trabajoDeFondoSondaDeCopias(trabajoDeFondoMetricas([
        BackupProbe::VERIFY_FILE => "kronoqr_backup_last_verify_result \$(rm -rf /)\n"
            ."kronoqr_backup_last_verified_timestamp_seconds <?php echo 1; ?>\n",
    ]));

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('never');
})->group('RF-PD-13', 'RNF-D-02');

it('usa el mismo umbral que la alerta CopiaDeSeguridadSinVerificar', function (): void {
    $rules = (string) file_get_contents(Repo::file('infra/observability/prometheus/rules/backup.yml'));

    expect($rules)->toMatch(
        '/alert: CopiaDeSeguridadSinVerificar\s+expr: >-\s+.*\n\s+or \(time\(\) - kronoqr_backup_last_verified_timestamp_seconds\) > '
        .BackupProbe::MAX_VERIFIED_AGE_SECONDS.'\n/'
    );
})->group('RF-PD-13', 'RNF-D-02');

// --- scheduler.heartbeat -----------------------------------------------------

it('da por vivo el planificador si la medida de cada minuto es reciente', function (): void {
    $finding = trabajoDeFondoSondaDelPlanificador(trabajoDeFondoMetricas([
        SchedulerProbe::FILE => trabajoDeFondoLatido(trabajoDeFondoInstante('2026-10-09 09:59:00')),
    ]));

    expect($finding->id)->toBe('scheduler.heartbeat')
        ->and($finding->status)->toBe(DoctorStatus::Ok)
        ->and(trabajoDeFondoTexto($finding))->toContain('09:59 UTC');
})->group('RF-PD-13');

it('avisa si el planificador lleva mas de cinco minutos sin lanzar su tarea de cada minuto', function (): void {
    $finding = trabajoDeFondoSondaDelPlanificador(trabajoDeFondoMetricas([
        SchedulerProbe::FILE => trabajoDeFondoLatido(trabajoDeFondoInstante('2026-10-09 09:40:00')),
    ]));

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBeNull()
        ->and(trabajoDeFondoTexto($finding))->toContain('hace 20 min')->toContain('scheduler')
        ->and(trabajoDeFondoArreglo($finding))->toContain('docker compose ps scheduler');
})->group('RF-PD-13');

it('sin latido avisa con la espera de una instalacion recien hecha', function (): void {
    $finding = trabajoDeFondoSondaDelPlanificador(trabajoDeFondoMetricas());

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('never');
})->group('RF-PD-13');

it('usa el mismo umbral que la alerta MedicionDeWalAusente', function (): void {
    $rules = (string) file_get_contents(Repo::file('infra/observability/prometheus/rules/backup.yml'));

    expect($rules)->toMatch(
        '/alert: MedicionDeWalAusente\s+expr: >-\s+\(time\(\) - kronoqr_wal_exporter_last_run_timestamp_seconds\) > '
        .SchedulerProbe::MAX_HEARTBEAT_AGE_SECONDS.'\n/'
    );
})->group('RF-PD-13');

// --- queue.worker ------------------------------------------------------------

/** Un `ServiceInspector` con la cola que se le diga, sin Redis ni base de datos de verdad. */
function trabajoDeFondoCola(int $pendientes, int $reservados): ServiceInspector
{
    /** @var RedisConnection&MockInterface $conexion */
    $conexion = Mockery::mock(RedisConnection::class);
    $conexion->shouldReceive('command')->andReturnUsing(
        static fn (string $comando, array $argumentos = []): string|int => match (true) {
            $comando === 'PING' => 'PONG',
            $comando === 'LLEN' => $pendientes,
            str_ends_with((string) ($argumentos[0] ?? ''), ':reserved') => $reservados,
            default => 0,
        },
    );

    /** @var Redis&MockInterface $redis */
    $redis = Mockery::mock(Redis::class);
    $redis->shouldReceive('connection')->andReturn($conexion);

    /** @var ConnectionInterface&MockInterface $baseDeDatos */
    $baseDeDatos = Mockery::mock(ConnectionInterface::class);
    $baseDeDatos->shouldReceive('table')->andThrow(new RuntimeException('sin base de datos en esta prueba'));

    return new ServiceInspector(
        database: $baseDeDatos,
        redis: $redis,
        migrationsPath: '/nonexistent',
        queueConnection: 'redis',
        queueName: 'default',
        realtimeEnabled: false,
        broadcastConnection: 'null',
    );
}

function trabajoDeFondoSupervisores(string $estado, ?int $maestros): QueueSupervisors
{
    return new readonly class($estado, $maestros) implements QueueSupervisors
    {
        public function __construct(private string $estado, private ?int $maestros) {}

        public function status(): array
        {
            return ['status' => $this->estado, 'masters' => $this->maestros];
        }
    };
}

function trabajoDeFondoConsumidor(ServiceInspector $cola, QueueSupervisors $supervisores): DoctorFinding
{
    $findings = new QueueProbe($cola, FixedClock::at(BACKGROUND_WORK_DOCTOR_CHECK_NOW), $supervisores)->run();

    foreach ($findings as $finding) {
        if ($finding->id === 'queue.worker') {
            return $finding;
        }
    }

    throw new RuntimeException('QueueProbe no devuelve queue.worker.');
}

it('con Horizon parado y la cola vacia falla, en lugar de decir que alguien la consume', function (): void {
    // V3-PL-07 / V4-PL-3: el «ok optimista» que el docblock de la sonda decia
    // evitar.
    $finding = trabajoDeFondoConsumidor(
        trabajoDeFondoCola(pendientes: 0, reservados: 0),
        trabajoDeFondoSupervisores(QueueSupervisors::INACTIVE, 0),
    );

    // FALLO y no aviso: la misma gravedad que le da doctor.sh desde fuera. Sin
    // Horizon no termina ninguna exportacion integra.
    expect($finding->status)->toBe(DoctorStatus::Failure)
        ->and($finding->variant)->toBe('stopped')
        ->and(trabajoDeFondoTexto($finding))->toContain('10:00 UTC')
        ->and(trabajoDeFondoTexto($finding))->not->toContain('Hay alguien consumiendo')
        ->and(trabajoDeFondoArreglo($finding))->toContain('docker compose up -d horizon');
})->group('RF-PD-13');

it('con Horizon en marcha lo da por bueno aunque la cola este vacia', function (): void {
    $finding = trabajoDeFondoConsumidor(
        trabajoDeFondoCola(pendientes: 0, reservados: 0),
        trabajoDeFondoSupervisores(QueueSupervisors::RUNNING, 1),
    );

    expect($finding->status)->toBe(DoctorStatus::Ok)
        ->and($finding->details['supervisors'] ?? null)->toBe(['status' => 'running', 'masters' => 1])
        ->and(trabajoDeFondoTexto($finding))->toContain('Horizon')->toContain('en marcha');
})->group('RF-PD-13');

it('con Horizon en pausa avisa y dice como reanudarlo', function (): void {
    $finding = trabajoDeFondoConsumidor(
        trabajoDeFondoCola(pendientes: 12, reservados: 0),
        trabajoDeFondoSupervisores(QueueSupervisors::PAUSED, 1),
    );

    expect($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('paused')
        ->and(trabajoDeFondoTexto($finding))->toContain('12')
        ->and(trabajoDeFondoArreglo($finding))->toContain('horizon:continue');
})->group('RF-PD-13');

it('sin poder preguntar a Horizon y con la cola vacia no afirma que haya consumidor', function (): void {
    $finding = trabajoDeFondoConsumidor(
        trabajoDeFondoCola(pendientes: 0, reservados: 0),
        trabajoDeFondoSupervisores(QueueSupervisors::UNKNOWN, null),
    );

    expect($finding->status)->toBe(DoctorStatus::Ok)
        ->and($finding->variant)->toBe('idle')
        ->and(trabajoDeFondoTexto($finding))->toContain('no se sabe')
        ->and(trabajoDeFondoTexto($finding))->not->toContain('Hay alguien consumiendo');
})->group('RF-PD-13');

it('sin poder preguntar a Horizon conserva la deduccion por la cola', function (int $pendientes, int $reservados, DoctorStatus $estado, ?string $variante): void {
    $finding = trabajoDeFondoConsumidor(
        trabajoDeFondoCola($pendientes, $reservados),
        trabajoDeFondoSupervisores(QueueSupervisors::UNKNOWN, null),
    );

    expect($finding->status)->toBe($estado)
        ->and($finding->variant)->toBe($variante);
})->with([
    'trabajos esperando y ninguno en proceso' => [40, 0, DoctorStatus::Warning, null],
    'trabajos en proceso' => [40, 3, DoctorStatus::Ok, 'busy'],
])->group('RF-PD-13');

it('lee el latido de Horizon como horizon:status', function (array $maestros, string $estado): void {
    /** @var MasterSupervisorRepository&MockInterface $repositorio */
    $repositorio = Mockery::mock(MasterSupervisorRepository::class);
    $repositorio->shouldReceive('all')->andReturn($maestros);

    expect(new HorizonQueueSupervisors($repositorio)->status()['status'])->toBe($estado);
})->with([
    'ninguno vivo' => [[], QueueSupervisors::INACTIVE],
    'uno en marcha' => [[(object) ['name' => 'm1', 'status' => 'running']], QueueSupervisors::RUNNING],
    'uno en pausa' => [[(object) ['name' => 'm1', 'status' => 'running'], (object) ['name' => 'm2', 'status' => 'paused']], QueueSupervisors::PAUSED],
])->group('RF-PD-13');

it('si no puede leer el latido de Horizon dice que no lo sabe, sin reventar', function (): void {
    /** @var MasterSupervisorRepository&MockInterface $repositorio */
    $repositorio = Mockery::mock(MasterSupervisorRepository::class);
    $repositorio->shouldReceive('all')->andThrow(new RedisException('Connection refused'));

    expect(new HorizonQueueSupervisors($repositorio)->status())->toBe(['status' => QueueSupervisors::UNKNOWN, 'masters' => null]);
})->group('RF-PD-13');
