<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\WorkRecordArchive;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `compliance:apply-retention` de punta a punta con la purga en la funcion
 * `SECURITY DEFINER` (ADR-057 §3, bloque 7 de la 2.2.1; RL-02, RL-04, RS-07,
 * RN-13, regla dura 6).
 *
 * Con el rol de la aplicacion, que ya no tiene `DELETE` sobre el registro: si la
 * orden borra, es que ha pasado por la funcion. El reloj de la aplicacion se fija
 * a la fecha del servidor de base de datos, que es el de la funcion; la segunda
 * prueba lo adelanta un año para comprobar que la funcion no se fia de el.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $directory = sys_get_temp_dir().'/kronoqr-apply-retention-'.bin2hex(random_bytes(6));

    Config::set('compliance.retention.report_path', $directory.'/informes');
    Config::set('compliance.retention.technical_log_path', $directory.'/logs');
    Config::set('observability.metrics.textfile_path', $directory.'/metricas');
    app()->setLocale('es');
});

/**
 * @return array{today: string, cutoff: string, expired: string}
 */
function applyRetentionDates(): array
{
    /** @var object{today: string} $row */
    $row = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");
    $cutoff = (new DateTimeImmutable($row->today.' 00:00:00', new DateTimeZone('UTC')))->sub(new DateInterval('P4Y'));

    return [
        'today' => $row->today,
        'cutoff' => $cutoff->format('Y-m-d'),
        'expired' => $cutoff->sub(new DateInterval('P1M'))->format('Y-m-d'),
    ];
}

/**
 * Un tramo de esa jornada con una correccion, un escaneo, una incidencia y su
 * total. Devuelve el id del tramo.
 */
function applyRetentionEntry(string $workDate): int
{
    $site = WorkforceFixtures::site();
    $employeeId = AttendanceFixtures::employeeIdOf(WorkforceFixtures::employee($site));
    $device = AttendanceFixtures::device($site);

    $entry = (int) DB::table('shift_entries')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $site,
        'work_date' => $workDate,
        'clocked_in_at' => $workDate.' 06:00:00+00',
        'clocked_out_at' => $workDate.' 14:00:00+00',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => $workDate.' 06:00:00+00',
        'updated_at' => $workDate.' 14:00:00+00',
    ]);

    DB::table('shift_corrections')->insert([
        'shift_entry_id' => $entry,
        'performed_by_user_id' => ManagementUsers::withRole(UserRole::ADMIN)->id,
        'action' => 'modified',
        'before' => json_encode(['duration_minutes' => 470]),
        'after' => json_encode(['duration_minutes' => 480]),
        'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        'reason_text' => null,
        'created_at' => $workDate.' 15:00:00+00',
    ]);

    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $device['id'],
        'employee_id' => $employeeId,
        'occurred_at' => $workDate.' 06:00:00+00',
        'recorded_at' => $workDate.' 06:00:01+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'shift_entry_id' => $entry,
        'worked_minutes' => 0,
        'client_meta' => json_encode([]),
    ]);

    DB::table('incidents')->insert([
        'employee_id' => $employeeId,
        'work_date' => $workDate,
        'shift_entry_id' => $entry,
        'type' => 'short_shift',
        'severity' => 'low',
        'status' => 'open',
        'detected_at' => $workDate.' 20:00:00+00',
        'context' => json_encode([]),
        'created_at' => $workDate.' 20:00:00+00',
        'updated_at' => $workDate.' 20:00:00+00',
    ]);

    DB::table('daily_totals')->insert([
        'employee_id' => $employeeId,
        'work_date' => $workDate,
        'total_minutes' => 480,
        'shift_count' => 1,
        'recalculated_at' => $workDate.' 23:00:00+00',
    ]);

    return $entry;
}

function applyRetentionToken(): string
{
    return app(PlanRetention::class)->handle(app(Clock::class)->now())->confirmationToken();
}

/**
 * @return array<string, int>
 */
function applyRetentionCounts(): array
{
    $counts = [];

    foreach (['shift_entries', 'shift_corrections', 'scan_events', 'incidents', 'daily_totals'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

it('purga lo vencido por la funcion, con el rol de la aplicacion y con su asiento', function (): void {
    /** @var object{role: string} $role */
    $role = DB::selectOne('SELECT current_user AS role');

    expect($role->role)->toBe(Config::string('database.roles.application'));

    $dates = applyRetentionDates();
    FrozenTime::at($dates['today'].' 04:00:00');

    $expired = applyRetentionEntry($dates['expired']);
    $current = applyRetentionEntry($dates['cutoff']);
    $responsible = ManagementUsers::withRole(UserRole::ADMIN);
    $before = DB::table('audit_log')->max('id') ?? 0;

    expect(Artisan::call('compliance:apply-retention', [
        '--confirm' => applyRetentionToken(),
        '--responsible' => (string) $responsible->id,
        '--batch' => '1',
    ]))->toBe(0, Artisan::output());

    expect(DB::table('shift_entries')->pluck('id')->all())->toBe([$current])
        ->and(DB::table('shift_entries')->where('id', $expired)->exists())->toBeFalse()
        ->and(applyRetentionCounts())->toBe([
            'shift_entries' => 1,
            'shift_corrections' => 1,
            'scan_events' => 1,
            'incidents' => 1,
            'daily_totals' => 1,
        ]);

    // Un asiento, en la misma transaccion que el borrado (regla dura 6).
    /** @var list<object{action: string, actor_type: string, actor_id: int|null, payload: string}> $entries */
    $entries = DB::table('audit_log')
        ->where('id', '>', $before)
        ->where('action', 'retention.purge_executed')
        ->get(['action', 'actor_type', 'actor_id', 'payload'])
        ->all();

    expect($entries)->toHaveCount(1);

    /** @var array{scope: string, cutoff_date: string, retention_years: int, retention_floor_years: int, jurisdiction: string, rows: int, tables: array<string, int>} $payload */
    $payload = json_decode($entries[0]->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($entries[0]->actor_type)->toBe('user')
        ->and($entries[0]->actor_id)->toBe($responsible->id)
        ->and($payload['scope'])->toBe('work_records')
        ->and($payload['cutoff_date'])->toBe($dates['cutoff'])
        ->and($payload['retention_years'])->toBe(4)
        ->and($payload['retention_floor_years'])->toBe(4)
        ->and($payload['jurisdiction'])->toBe('ES')
        ->and($payload['rows'])->toBe(5)
        // El asiento guarda el JSON canonico, con las claves ordenadas.
        ->and($payload['tables'])->toEqual([
            'shift_corrections' => 1,
            'incidents' => 1,
            'scan_events' => 1,
            'discarded_scan_reports' => 0,
            'shift_entries' => 1,
            'daily_totals' => 1,
        ]);
})->group('RL-02', 'RL-04', 'RS-07', 'RN-13', 'RF-PR-03');

it('no borra nada ni deja asiento si el reloj de la aplicacion pide un corte que la funcion no admite', function (): void {
    $dates = applyRetentionDates();

    // La aplicacion cree que es dentro de un año: su corte cae un año despues
    // del admisible. La funcion calcula el suyo con el reloj de PostgreSQL.
    FrozenTime::at((new DateTimeImmutable($dates['today']))->modify('+1 year')->format('Y-m-d').' 04:00:00');

    applyRetentionEntry($dates['expired']);
    // Vencido para la aplicacion, vigente para la funcion.
    applyRetentionEntry((new DateTimeImmutable($dates['cutoff']))->modify('+6 months')->format('Y-m-d'));

    $before = applyRetentionCounts();
    $audit = DB::table('audit_log')->count();

    $exit = Artisan::call('compliance:apply-retention', [
        '--confirm' => applyRetentionToken(),
        '--responsible' => (string) ManagementUsers::withRole(UserRole::ADMIN)->id,
    ]);

    $output = Artisan::output();

    expect($output)->not->toContain('pgsql_maintenance');

    expect($exit)->toBe(1)
        ->and($output)->toContain('PostgreSQL ha rechazado el corte')
        // La funcion toma el plazo mas largo de todos los centros.
        ->toContain('todos los centros')
        // El texto con el que el comando reconoce el rechazo es el de la
        // funcion de la migracion: si uno cambia, esto rompe.
        ->toContain(DatabaseWorkRecordArchive::CUTOFF_REJECTED)
        ->and(applyRetentionCounts())->toBe($before)
        ->and(DB::table('audit_log')->count())->toBe($audit);
})->group('RL-02', 'RL-04', 'RS-07', 'RN-13');

/**
 * Un `QueryException` con ese SQLSTATE, como lo lanzaria el controlador: el
 * mensaje lleva la sentencia, que nombra la funcion de purga.
 */
function applyRetentionFailure(string $sqlState, string $message): QueryException
{
    $driver = new class($message) extends PDOException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);
        }
    };

    (new ReflectionProperty(Exception::class, 'code'))->setValue($driver, $sqlState);

    return new QueryException('pgsql', 'SELECT dataset, deleted FROM public.work_record_purge_batch(?::date, ?::integer)', [], $driver);
}

it('explica cada fallo de la purga por su SQLSTATE, no por el nombre de la funcion', function (string $sqlState, string $message, string $expected, string $notExpected): void {
    $dates = applyRetentionDates();
    applyRetentionEntry($dates['expired']);

    // Lo que se inspecciona es lo de verdad; el borrado falla como lo haria la
    // funcion. El mensaje del controlador nombra siempre la funcion: antes eso
    // bastaba para decir «revisa los relojes» ante cualquier fallo.
    $real = app(DatabaseWorkRecordArchive::class);
    app()->instance(WorkRecordArchive::class, new readonly class($real, applyRetentionFailure($sqlState, $message)) implements WorkRecordArchive
    {
        public function __construct(private WorkRecordArchive $real, private QueryException $failure) {}

        public function inspect(DateTimeImmutable $cutoff): array
        {
            return $this->real->inspect($cutoff);
        }

        public function purge(DateTimeImmutable $cutoff, int $batchSize): array
        {
            throw $this->failure;
        }
    });

    $before = applyRetentionCounts();
    $audit = DB::table('audit_log')->count();

    $exit = Artisan::call('compliance:apply-retention', [
        '--confirm' => applyRetentionToken(),
        '--responsible' => (string) ManagementUsers::withRole(UserRole::ADMIN)->id,
    ]);

    $output = Artisan::output();

    expect($output)->not->toContain($notExpected);

    expect($exit)->toBe(1)
        ->and($output)->toContain('No se ha borrado nada')
        ->toContain($expected)
        ->and(applyRetentionCounts())->toBe($before)
        ->and(DB::table('audit_log')->count())->toBe($audit);
})->with([
    'bloqueo (55P03)' => [
        '55P03',
        'SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout',
        'Vuelve a ejecutar la misma orden',
        'PostgreSQL ha rechazado el corte',
    ],
    'sin perfil (P0002)' => [
        'P0002',
        'SQLSTATE[P0002]: No data found: 7 ERROR:  work_record_purge_batch: no hay perfil de cumplimiento con el que calcular el corte',
        'No hay ningún perfil de cumplimiento',
        'PostgreSQL ha rechazado el corte',
    ],
    'otro 42501 (sin EXECUTE)' => [
        '42501',
        'SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for function work_record_purge_batch',
        'pgsql_maintenance',
        'PostgreSQL ha rechazado el corte',
    ],
])->group('RL-02', 'RF-PR-03');

// --- `--responsible`: un id de verdad y una cuenta activa (bloque 23 de la 2.2.1) ---

/**
 * Una entrada vencida y otra vigente, y el reloj en hoy. Devuelve el estado de
 * partida para afirmar despues que no ha cambiado nada.
 *
 * @return array{counts: array<string, int>, audit: int}
 */
function applyRetentionResponsibleScenario(): array
{
    $dates = applyRetentionDates();
    FrozenTime::at($dates['today'].' 04:00:00');

    applyRetentionEntry($dates['expired']);
    applyRetentionEntry($dates['cutoff']);

    return ['counts' => applyRetentionCounts(), 'audit' => DB::table('audit_log')->count()];
}

it('sale con 2 sin simular ni purgar si --responsible no es un id', function (string $valor, bool $simular): void {
    $antes = applyRetentionResponsibleScenario();
    $orden = $simular ? ['--dry-run' => true] : ['--confirm' => applyRetentionToken()];

    $exit = Artisan::call('compliance:apply-retention', [...$orden, '--responsible' => $valor]);
    $salida = Artisan::output();

    // `12abc` ya no es la cuenta 12: antes el `(int)` lo dejaba pasar.
    expect($exit)->toBe(2, $salida)
        ->and($salida)->toContain('entero positivo')
        ->and(applyRetentionCounts())->toBe($antes['counts'])
        ->and(DB::table('audit_log')->count())->toBe($antes['audit']);
})->with([
    'con letras detras, al ejecutar' => ['12abc', false],
    'con letras detras, al simular' => ['12abc', true],
    'negativo' => ['-3', false],
    'cero' => ['0', false],
    'decimal' => ['1.5', false],
])->group('RF-PR-03', 'RL-04');

it('sale con 2 sin simular ni purgar si --responsible nombra una cuenta que no existe', function (bool $simular): void {
    $antes = applyRetentionResponsibleScenario();
    $orden = $simular ? ['--dry-run' => true] : ['--confirm' => applyRetentionToken()];
    $inexistente = ManagementUsers::withRole(UserRole::ADMIN)->id + 1000;

    $exit = Artisan::call('compliance:apply-retention', [...$orden, '--responsible' => (string) $inexistente]);

    $salida = Artisan::output();

    expect($exit)->toBe(2, $salida)
        ->and($salida)->toContain('no existe o no está activa')
        ->and(applyRetentionCounts())->toBe($antes['counts'])
        ->and(DB::table('audit_log')->count())->toBe($antes['audit']);
})->with([
    'al ejecutar' => [false],
    'al simular' => [true],
])->group('RF-PR-03', 'RL-04');

it('sale con 2 sin purgar si --responsible es una cuenta dada de baja', function (): void {
    $antes = applyRetentionResponsibleScenario();
    $baja = ManagementUsers::withRole(UserRole::ADMIN);
    DB::table('users')->where('id', $baja->id)->update(['is_active' => false]);

    $exit = Artisan::call('compliance:apply-retention', [
        '--confirm' => applyRetentionToken(),
        '--responsible' => (string) $baja->id,
    ]);

    expect($exit)->toBe(2, Artisan::output())
        ->and(applyRetentionCounts())->toBe($antes['counts'])
        ->and(DB::table('audit_log')->count())->toBe($antes['audit']);
})->group('RF-PR-03', 'RL-04');

it('deshace la purga si la cuenta deja de estar activa entre la simulacion y el asiento', function (): void {
    // La segunda comprobacion va con la cadena de auditoria tomada, justo antes
    // del asiento: si la cuenta se dio de baja entre medias, no hay asiento y,
    // por tanto, tampoco borrado (regla dura 6).
    $antes = applyRetentionResponsibleScenario();
    $responsable = ManagementUsers::withRole(UserRole::ADMIN);

    app()->instance(ResponsibleAccounts::class, new class implements ResponsibleAccounts
    {
        private int $preguntas = 0;

        public function isActiveManagementAccount(int $userId, array $roles = []): bool
        {
            // Activa en la primera comprobacion; dada de baja en la segunda.
            return ++$this->preguntas === 1;
        }
    });

    $exit = Artisan::call('compliance:apply-retention', [
        '--confirm' => applyRetentionToken(),
        '--responsible' => (string) $responsable->id,
    ]);

    expect($exit)->toBe(2, Artisan::output())
        ->and(applyRetentionCounts())->toBe($antes['counts'])
        ->and(DB::table('audit_log')->where('action', 'retention.purge_executed')->count())->toBe(0)
        ->and(DB::table('audit_log')->count())->toBe($antes['audit']);
})->group('RF-PR-03', 'RL-04', 'RS-07');
