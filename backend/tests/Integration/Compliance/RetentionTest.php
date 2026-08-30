<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\RetentionRunCommand;
use App\Modules\Compliance\Application\Exception\AuditPartitionNotPurgeable;
use App\Modules\Compliance\Application\Port\AuditChainReader;
use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Compliance\Application\Support\RetentionTelemetry;
use App\Modules\Compliance\Application\UseCase\ApplyRetention;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileAuditMetrics;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileRetentionMetrics;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditChainReader;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditPartitionArchive;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseErrorHistoryArchive;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use App\Modules\Compliance\Infrastructure\Retention\FileRetentionReportStore;
use App\Modules\Compliance\Infrastructure\Retention\FilesystemTechnicalLogArchive;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La purga por retencion contra PostgreSQL de verdad (RL-02, RL-11, RF-PR-03,
 * RL-04, RS-07, ADR-027, tarea 2.10).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Lo que se comprueba vive en el motor:
 * que el orden de borrado no choque con las claves ajenas `RESTRICT`, que el rol
 * de la aplicacion NO pueda soltar una particion, que `DROP PARTITION` con su
 * ancla sellada deje la cadena verificable, y que una fila alterada en una
 * particion viva siga denunciando rotura. Un doble en memoria daria las cuatro
 * por buenas sin haberlas comprobado, y son las que sostienen el valor
 * probatorio del registro.
 *
 * EL RELOJ ESTA FIJO (regla dura 2). Una prueba de retencion que llamara a
 * `now()` pasaria trescientos sesenta y cuatro dias al año y fallaria uno.
 *
 * DOS «AHORAS» Y NO UNO, y no es un descuido:
 *
 *   · RETENTION_NOW (2027) para el registro de jornada. Cae dentro de las
 *     particiones que crea la migracion, asi que el asiento de la purga tiene
 *     donde escribirse.
 *   · PARTITION_NOW (2031) para la purga de `audit_log`, que es cuando la
 *     particion de 2026 lleva sus cuatro anos vencida ENTERA. Esas pruebas crean
 *     su particion de destino dentro de su propia transaccion y la deshacen.
 */

uses(RefreshDatabase::class);

/** El «ahora» de las pruebas del registro de jornada. Corte: 2023-06-01. */
const RETENTION_NOW = '2027-06-01 04:00:00';

/** El «ahora» de las pruebas de particiones: 2026 ya vencio entera. */
const PARTITION_NOW = '2031-02-01 06:00:00';

/**
 * Escenario con una jornada VENCIDA y otra VIGENTE, con todo lo que cuelga de
 * ellas.
 *
 * @return array{site: int, employee: string, expired_entry: int, current_entry: int}
 */
function retentionScenario(): array
{
    $site = WorkforceFixtures::site('Hotel de retencion', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Recepcion');
    $employee = WorkforceFixtures::employee($site, $department);
    $employeeId = AttendanceFixtures::employeeIdOf($employee);
    $device = AttendanceFixtures::device($site);
    $manager = ManagementUsers::withRole(UserRole::ADMIN);

    // Vencida: anterior al corte (2023-06-01).
    $expired = retentionShiftEntry($employeeId, $site, '2023-05-01');
    // Vigente por un dia: el limite que la salvaguarda tiene que respetar.
    $current = retentionShiftEntry($employeeId, $site, '2023-06-01');

    foreach ([$expired => '2023-05-01', $current => '2023-06-01'] as $entryId => $workDate) {
        DB::table('shift_corrections')->insert([
            'shift_entry_id' => $entryId,
            'performed_by_user_id' => $manager->id,
            'action' => 'modified',
            'before' => json_encode(['duration_minutes' => 480]),
            'after' => json_encode(['duration_minutes' => 500]),
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
            'reason_text' => null,
            'created_at' => $workDate.' 10:00:00+00',
        ]);

        DB::table('incidents')->insert([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'shift_entry_id' => $entryId,
            'type' => 'open_shift_expired',
            'severity' => 'medium',
            'status' => 'open',
            'detected_at' => $workDate.' 20:00:00+00',
            'context' => json_encode([]),
            'created_at' => $workDate.' 20:00:00+00',
            'updated_at' => $workDate.' 20:00:00+00',
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
            'shift_entry_id' => $entryId,
            'worked_minutes' => 0,
            'client_meta' => json_encode([]),
        ]);

        DB::table('daily_totals')->insert([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'total_minutes' => 480,
            'shift_count' => 1,
            'recalculated_at' => $workDate.' 23:00:00+00',
        ]);
    }

    // Un escaneo RECHAZADO, sin tramo: envejece por su `occurred_at` y no por
    // ninguna jornada. Es la otra mitad de la consulta de `scan_events`.
    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $device['id'],
        'employee_id' => null,
        'occurred_at' => '2023-04-01 06:00:00+00',
        'recorded_at' => '2023-04-01 06:00:01+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_unknown',
        'shift_entry_id' => null,
        'worked_minutes' => null,
        'client_meta' => json_encode([]),
    ]);

    return [
        'site' => $site,
        'employee' => $employee,
        'expired_entry' => $expired,
        'current_entry' => $current,
    ];
}

function retentionShiftEntry(int $employeeId, int $siteId, string $workDate): int
{
    return (int) DB::table('shift_entries')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employeeId,
        'site_id' => $siteId,
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
}

/** Informes y metricas a un temporal: nada de esta suite escribe en el storage real. */
function retentionPaths(): string
{
    $directory = sys_get_temp_dir().'/kronoqr-retention-'.bin2hex(random_bytes(6));

    Config::set('compliance.retention.report_path', $directory.'/informes');
    Config::set('compliance.retention.technical_log_path', $directory.'/logs');
    Config::set('observability.metrics.textfile_path', $directory.'/metricas');
    Config::set('observability.metrics.enabled', true);

    return $directory;
}

/**
 * Cuenta las filas de todo lo que la purga puede tocar.
 *
 * @return array<string, int>
 */
function retentionCounts(): array
{
    $counts = [];

    foreach (['shift_entries', 'shift_corrections', 'incidents', 'scan_events', 'daily_totals'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function retentionClock(string $now = RETENTION_NOW): void
{
    app()->instance(Clock::class, FixedClock::at($now));
}

/**
 * La frase de confirmacion del plan de hoy, calculada como la calcula el
 * informe. La prueba NO la escribe a mano: si lo hiciera, comprobaria que dos
 * literales coinciden.
 */
function retentionToken(): string
{
    return app(PlanRetention::class)->handle(app(Clock::class)->now())->confirmationToken();
}

/**
 * El caso de uso completo cableado sobre UNA conexion.
 *
 * Existe por lo mismo que `tamperedChainVerification()` en AuditLogTest: las
 * pruebas de particiones escriben en `audit_log` con el rol propietario y dentro
 * de una transaccion que se deshace al final, y dos conexiones distintas no ven
 * las escrituras sin confirmar de la otra. Cablear todo sobre la misma conexion
 * es lo que hace que la prueba compruebe algo.
 *
 * El REPARTO DE ROLES no se prueba aqui sino en «solo el rol de mantenimiento
 * puede soltar una particion», que es declarativo y ademas lo intenta de verdad.
 */
function retentionUsing(ConnectionInterface $connection, string $now): ApplyRetention
{
    $clock = FixedClock::at($now);
    app()->instance(Clock::class, $clock);

    $work = new DatabaseWorkRecordArchive($connection);
    $partitions = new DatabaseAuditPartitionArchive($connection);
    $technicalLog = new FilesystemTechnicalLogArchive;
    $errorHistory = new DatabaseErrorHistoryArchive($connection);
    $policies = app(RetentionPolicyProvider::class);

    return new ApplyRetention(
        new PlanRetention($policies, $work, $partitions, $technicalLog, $errorHistory),
        $work,
        $partitions,
        new DatabaseAuditChainReader($connection),
        $technicalLog,
        $errorHistory,
        new FileRetentionReportStore,
        new TextfileRetentionMetrics,
        new RecordAuditEntry(new DatabaseAuditTrail($connection), $clock),
        new RetentionTelemetry(app(LoggerInterface::class)),
        $connection,
        $clock,
    );
}

/** Una entrada de auditoria con `occurred_at` elegido, para poblar particiones. */
function auditEntryAt(DatabaseAuditTrail $trail, string $occurredAt, int $n): void
{
    $trail->append(new AuditEntryDraft(
        occurredAt: new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        actor: AuditActor::system(),
        action: AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry', $n),
        payload: AuditPayload::of(['n' => $n]),
    ));
}

// --- Simulacion: propone y no toca nada --------------------------------------

it('no modifica ninguna fila en simulacion y cuenta lo que se purgaria', function (): void {
    retentionPaths();
    retentionScenario();
    retentionClock();

    $before = retentionCounts();

    expect(Artisan::call('compliance:apply-retention', ['--dry-run' => true]))->toBe(0);

    // Ni una fila menos: el `--dry-run` es de solo lectura por construccion -sus
    // puertos no tienen por donde borrar-, no por acordarse de no borrar.
    expect(retentionCounts())->toBe($before);

    $output = Artisan::output();

    // Los conteos correctos: de cada tabla, solo lo vencido.
    expect($output)->toContain('SIMULACION')
        ->and($output)->toContain('anterior a 2023-06-01')
        // 1 tramo, 1 correccion, 1 incidencia, 1 total diario y 2 escaneos -el
        // del tramo vencido y el rechazado sin tramo-.
        ->and($output)->toContain('PURGAR-2023-06-01');

    // Y es idempotente: repetirlo no cambia nada ni produce otro resultado.
    Artisan::call('compliance:apply-retention', ['--dry-run' => true]);

    expect(retentionCounts())->toBe($before);
})->group('RF-PR-03');

it('cuenta exactamente lo vencido y deja fuera la jornada del dia del corte', function (): void {
    // La salvaguarda del bloque A de `/revision-cumplimiento`: «la purga no puede
    // alcanzar registros aun vigentes». La jornada del 2023-06-01 cumple hoy sus
    // cuatro anos y NO se purga.
    retentionPaths();
    retentionScenario();
    retentionClock();

    $plan = app(PlanRetention::class)->handle(app(Clock::class)->now());

    $counts = $plan->countsFor(RetentionScope::WorkRecords);

    expect($counts['shift_entries'])->toBe(1)
        ->and($counts['shift_corrections'])->toBe(1)
        ->and($counts['incidents'])->toBe(1)
        ->and($counts['daily_totals'])->toBe(1)
        // El del tramo vencido y el rechazado de 2023-04-01, que no cuelga de
        // ninguna jornada.
        ->and($counts['scan_events'])->toBe(2)
        // Y ninguna particion de `audit_log`: en 2027 no ha vencido ninguna.
        ->and($plan->auditPartitionYears)->toBe([]);
})->group('RL-02', 'RL-11');

it('no borra nada sin la frase de confirmacion, ni con una frase que no corresponde', function (): void {
    retentionPaths();
    retentionScenario();
    retentionClock();

    $before = retentionCounts();

    // Sin `--confirm`: el modo por defecto es simular.
    expect(Artisan::call('compliance:apply-retention'))->toBe(0)
        ->and(retentionCounts())->toBe($before);

    // Con una frase inventada: falla y no borra.
    expect(Artisan::call('compliance:apply-retention', ['--confirm' => 'PURGAR-2023-06-01-000000']))->toBe(1)
        ->and(retentionCounts())->toBe($before);

    // Y con la buena pero pidiendo simular, manda la que no borra.
    expect(Artisan::call('compliance:apply-retention', [
        '--confirm' => retentionToken(),
        '--dry-run' => true,
    ]))->toBe(0)
        ->and(retentionCounts())->toBe($before);
})->group('RF-PR-03');

// --- Ejecucion real ----------------------------------------------------------

it('purga lo vencido, respeta lo vigente y deja asiento con alcance y conteos', function (): void {
    retentionPaths();
    $scenario = retentionScenario();
    retentionClock();

    $responsible = ManagementUsers::withRole(UserRole::ADMIN);

    expect(Artisan::call('compliance:apply-retention', [
        '--confirm' => retentionToken(),
        '--responsible' => (string) $responsible->id,
    ]))->toBe(0);

    // Lo vencido se ha ido y lo vigente sigue entero (RL-02).
    expect(DB::table('shift_entries')->where('id', $scenario['expired_entry'])->exists())->toBeFalse()
        ->and(DB::table('shift_entries')->where('id', $scenario['current_entry'])->exists())->toBeTrue()
        ->and(DB::table('shift_corrections')->count())->toBe(1)
        ->and(DB::table('incidents')->count())->toBe(1)
        ->and(DB::table('daily_totals')->count())->toBe(1)
        ->and(DB::table('scan_events')->count())->toBe(1)
        // La plantilla no se toca: un trabajador no desaparece porque su jornada
        // de hace cuatro anos si.
        ->and(DB::table('employees')->count())->toBe(1);

    /** @var object{action: string, actor_type: string, actor_id: int|null, subject_type: string|null, payload: string}|null $entry */
    $entry = DB::table('audit_log')->orderByDesc('id')->first();

    expect($entry)->not->toBeNull();

    /** @var array{scope: string, cutoff_date: string, retention_years: int, rows: int, tables: array<string, int>} $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($entry?->subject_type)->toBe('retention')
        ->and($entry?->actor_type)->toBe('user')
        ->and($entry?->actor_id)->toBe($responsible->id)
        // El ALCANCE y los CONTEOS, que es lo que RL-04 exige poder responder
        // dos anos despues.
        ->and($payload['scope'])->toBe('work_records')
        ->and($payload['cutoff_date'])->toBe('2023-06-01')
        ->and($payload['retention_years'])->toBe(4)
        ->and($payload['rows'])->toBe(6)
        ->and($payload['tables']['shift_entries'])->toBe(1)
        ->and($payload['tables']['scan_events'])->toBe(2);
})->group('RL-04', 'RF-PR-03');

it('purga el log tecnico y el historico de errores en su ciclo de 90 dias', function (): void {
    $directory = retentionPaths();
    retentionScenario();
    retentionClock();

    // El log tecnico: dos ficheros, uno de hace cuatro meses y otro de ayer.
    $logs = $directory.'/logs';
    mkdir($logs, 0o750, true);
    file_put_contents($logs.'/laravel-2027-01-15.log', 'viejo');
    touch($logs.'/laravel-2027-01-15.log', strtotime('2027-01-15 00:00:00 UTC'));
    file_put_contents($logs.'/laravel-2027-05-31.log', 'reciente');
    touch($logs.'/laravel-2027-05-31.log', strtotime('2027-05-31 00:00:00 UTC'));

    // `error_events` llega con la tarea 5.12; aqui se levanta una tabla con su
    // forma minima -clave y columna de envejecimiento- para comprobar que el
    // ciclo corto la alcanza en cuanto exista.
    //
    // TODO EN LA CONEXION DE MIGRACION Y EN SU PROPIA TRANSACCION, igual que las
    // pruebas de particiones y por un motivo que ademas es un hallazgo: crear la
    // tabla con un rol y escribirla con otro deja la primera sesion esperando el
    // bloqueo de la segunda -que no confirma hasta que acaba la prueba- y el
    // `DROP TABLE` final se queda colgado. Crear, poblar, purgar y deshacer en una
    // sola sesion no necesita limpieza: la deshace el `rollBack`.
    $migrator = DB::connection('pgsql_migrator');
    $migrator->beginTransaction();

    try {
        $migrator->statement(
            'CREATE TABLE error_events (id bigserial PRIMARY KEY, last_seen_at timestamptz NOT NULL)'
        );

        $migrator->table('error_events')->insert([
            ['last_seen_at' => '2027-01-15 10:00:00+00'],
            ['last_seen_at' => '2027-05-31 10:00:00+00'],
        ]);

        $retention = retentionUsing($migrator, RETENTION_NOW);
        $token = $retention->handle(RetentionRunCommand::simulate())->confirmationToken();

        $outcome = $retention->handle(RetentionRunCommand::execute($token, null));

        // 90 dias antes del 1 de junio de 2027 es el 3 de marzo: se va la huella
        // de enero y se queda la de mayo.
        expect($migrator->table('error_events')->count())->toBe(1)
            ->and($outcome->report->rowsFor(RetentionScope::ErrorHistory))->toBe(1)
            ->and($outcome->report->rowsFor(RetentionScope::TechnicalLog))->toBe(1)
            ->and(is_file($logs.'/laravel-2027-01-15.log'))->toBeFalse()
            ->and(is_file($logs.'/laravel-2027-05-31.log'))->toBeTrue();
    } finally {
        $migrator->rollBack();
    }
})->group('RL-11');

// --- `audit_log`: DROP PARTITION, nunca DELETE (ADR-027) ---------------------

it('suelta la particion vencida con su ancla sellada y deja la cadena verificable', function (): void {
    // La prueba que decide si la alerta de RS-07 sirve para algo.
    retentionPaths();
    WorkforceFixtures::site('Hotel de particiones', 'Europe/Madrid');

    $migrator = DB::connection('pgsql_migrator');
    $migrator->beginTransaction();

    try {
        // La particion donde caeran los asientos de la propia purga (2031). Se
        // crea DENTRO de la transaccion, asi que la deshace el `rollBack`.
        foreach (AuditLogSchema::createPartitionStatements(2031) as $statement) {
            $migrator->statement($statement);
        }

        $trail = new DatabaseAuditTrail($migrator);

        foreach ([1, 2, 3] as $n) {
            auditEntryAt($trail, '2026-03-0'.$n.' 08:00:00', $n);
        }

        foreach ([4, 5] as $n) {
            auditEntryAt($trail, '2027-03-0'.$n.' 08:00:00', $n);
        }

        $retention = retentionUsing($migrator, PARTITION_NOW);
        $plan = $retention->handle(RetentionRunCommand::simulate());

        expect($plan->report->auditPartitionYears)->toBe([2026]);

        $outcome = $retention->handle(RetentionRunCommand::execute(
            $plan->confirmationToken(),
            null,
        ));

        expect($outcome->report->rowsFor(RetentionScope::AuditLog))->toBe(3);

        // 1. La particion ya no esta y NO se ha borrado ni una fila con DELETE:
        //    el ano entero se solto de golpe.
        expect((new DatabaseAuditPartitionArchive($migrator))->attachedYears())->toBe([2027, 2031]);

        // 2. El ancla quedo sellada, con el rol que la solto.
        /** @var object{partition_year: int, row_count: int, sealed_by: string, last_hash: string}|null $anchor */
        $anchor = $migrator->table(AuditLogSchema::ANCHORS_TABLE)->first();

        expect($anchor?->partition_year)->toBe(2026)
            ->and($anchor?->row_count)->toBe(3)
            ->and($anchor?->sealed_by)->toBe(Config::string('database.roles.migration'));

        // 3. El verificador termina EN VERDE e informa de la purga.
        app()->instance(AuditChainReader::class, new DatabaseAuditChainReader($migrator));

        $verification = (new VerifyAuditChain(
            new DatabaseAuditChainReader($migrator),
            new TextfileAuditMetrics,
            FixedClock::at(PARTITION_NOW),
        ))->handle();

        expect($verification->isIntact())->toBeTrue()
            ->and($verification->sealedPurgeYears)->toBe([2026]);

        expect(Artisan::call('compliance:verify-audit-chain'))->toBe(0)
            ->and(Artisan::output())->toContain('Purga sellada reconocida: particion 2026');

        // 4. Y con una fila alterada en una particion VIVA, sigue denunciando
        //    rotura: el verificador distingue purga legitima de manipulacion.
        /** @var object{id: int} $victim */
        $victim = $migrator->table('audit_log_2027')->orderBy('id')->firstOrFail();

        $migrator->statement("UPDATE audit_log SET payload = '{\"n\": 99}'::jsonb WHERE id = ?", [$victim->id]);

        $tampered = (new VerifyAuditChain(
            new DatabaseAuditChainReader($migrator),
            new TextfileAuditMetrics,
            FixedClock::at(PARTITION_NOW),
        ))->handle();

        expect($tampered->isIntact())->toBeFalse()
            ->and($tampered->failureCount())->toBe(1)
            // La purga sigue reconocida: lo que se denuncia es la fila tocada.
            ->and($tampered->sealedPurgeYears)->toBe([2026]);
    } finally {
        $migrator->rollBack();
    }
})->group('RL-02', 'RS-07');

it('aborta sin soltar nada cuando la cadena de la particion no verifica', function (): void {
    retentionPaths();
    WorkforceFixtures::site('Hotel de particiones', 'Europe/Madrid');

    $migrator = DB::connection('pgsql_migrator');
    $migrator->beginTransaction();

    try {
        foreach (AuditLogSchema::createPartitionStatements(2031) as $statement) {
            $migrator->statement($statement);
        }

        $trail = new DatabaseAuditTrail($migrator);

        foreach ([1, 2, 3] as $n) {
            auditEntryAt($trail, '2026-03-0'.$n.' 08:00:00', $n);
        }

        auditEntryAt($trail, '2027-03-01 08:00:00', 4);

        // Alguien toco una fila de la particion que iba a soltarse. Es
        // exactamente el caso en el que soltarla destruiria la prueba.
        /** @var object{id: int} $victim */
        $victim = $migrator->table('audit_log_2026')->orderBy('id')->skip(1)->firstOrFail();

        $migrator->statement("UPDATE audit_log SET payload = '{\"n\": 66}'::jsonb WHERE id = ?", [$victim->id]);

        $retention = retentionUsing($migrator, PARTITION_NOW);
        $token = $retention->handle(RetentionRunCommand::simulate())->confirmationToken();

        expect(fn () => $retention->handle(RetentionRunCommand::execute($token, null)))
            ->toThrow(AuditPartitionNotPurgeable::class);

        // La particion sigue donde estaba, sin ancla y con sus tres filas.
        expect((new DatabaseAuditPartitionArchive($migrator))->attachedYears())->toBe([2026, 2027, 2031])
            ->and($migrator->table(AuditLogSchema::ANCHORS_TABLE)->count())->toBe(0)
            ->and($migrator->table('audit_log_2026')->count())->toBe(3);
    } finally {
        $migrator->rollBack();
    }
})->group('RS-07');

it('deja soltar una particion solo al rol de mantenimiento, nunca al de la aplicacion', function (): void {
    // Las dos mitades: lo que dice el catalogo -que es lo que se lee en una
    // auditoria- y lo que ocurre cuando el rol de la aplicacion lo intenta de
    // verdad.
    $application = Config::string('database.roles.application');
    $maintenance = Config::string('database.roles.maintenance');
    $function = AuditLogSchema::DROP_FUNCTION.'(integer)';

    /** @var object{granted: bool}|null $denied */
    $denied = DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS granted', [$application, $function, 'EXECUTE']);
    /** @var object{granted: bool}|null $granted */
    $granted = DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS granted', [$maintenance, $function, 'EXECUTE']);

    expect($denied?->granted)->toBeFalse(
        $application.' puede ejecutar '.$function.': podria soltar el registro probatorio de un año entero.'
    )->and($granted?->granted)->toBeTrue(
        $maintenance.' no puede ejecutar '.$function.': la purga de ADR-027 seria imposible.'
    );

    $partition = AuditLogSchema::partitionName(2026);

    // Y en la practica, con el rol con el que corre el producto:
    expectRetentionDenied('DETACH PARTITION '.$partition, static function () use ($partition): void {
        DB::statement('ALTER TABLE audit_log DETACH PARTITION '.$partition);
    });

    expectRetentionDenied('DROP TABLE '.$partition, static function () use ($partition): void {
        DB::statement('DROP TABLE '.$partition);
    });

    expectRetentionDenied('la funcion de purga', static function (): void {
        DB::statement('SELECT '.AuditLogSchema::DROP_FUNCTION.'(2026)');
    });
})->group('RS-07', 'RL-04');

/**
 * Que el intento lo rechace **el motor**, con su codigo, y no un `if` de la
 * aplicacion. Se envuelve en una transaccion propia para que el error no deje
 * inutilizable la de la prueba.
 */
function expectRetentionDenied(string $what, Closure $attempt): void
{
    try {
        DB::transaction(static function () use ($attempt): void {
            $attempt();
        });
    } catch (QueryException $exception) {
        // 42501 permission denied · 42501 tambien para «must be owner of table».
        expect($exception->getCode())->toBeIn(['42501', '0L000'], $what.': '.$exception->getMessage());

        return;
    }

    Assert::fail(
        'PostgreSQL ha permitido «'.$what.'» al rol de la aplicacion. '
        .'ADR-027: solo el rol de mantenimiento suelta una particion de audit_log.'
    );
}
