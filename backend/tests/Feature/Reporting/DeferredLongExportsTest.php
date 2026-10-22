<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Reporting\Application\Port\ReportExportProgressBoard;
use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Application\UseCase\CancelReportExport;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportParameters;
use App\Modules\Reporting\Domain\ValueObject\ReportExportProgress;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStage;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Modules\Reporting\Infrastructure\Job\GenerateReportExportJob;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\LegalRecordFileWriter;
use App\Modules\Shared\Domain\ValueObject\LegalRecordFile;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Compliance\AuditPayloads;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\RecordingReportExportProgressBoard;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las operaciones largas del panel pasan a diferido (2.3.0, bloque 14, tarea
 * 14.4; V5-PA-4, RC2, V21-5-PA-2): la exportacion para la Inspeccion
 * (`kind: legal`), el cuadro de impacto (`kind: adoption`), la cancelacion y el
 * avance de las que se estan generando.
 *
 * Lo que se afirma aqui, y no en `ReportExportEndpointTest`:
 *
 * - **la exportacion legal no se degrada** —sin licencia, `202`— y es del
 *   auditor tanto como de RRHH;
 * - el fichero es **el de la sincrona** y deja **el mismo asiento**
 *   (`legal_export.generated`) a nombre de quien la pidio;
 * - el ambito del token cuenta **ademas** del rol;
 * - cancelar libera el turno, es idempotente, no tira un fichero ya generado y
 *   detiene una generacion a medias **borrando lo escrito**;
 * - la lista enseña el avance de las `running` y de ninguna otra.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    App::setLocale('es');
    LicenseKeys::install();
    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    ReportExports::cleanUpTemporaryPath();
});

/**
 * Un centro con una persona y una jornada de ocho horas el 14 de marzo: lo que
 * una exportacion legal de marzo tiene que llevar.
 *
 * @return array{site: int, employee: string}
 */
function diferidoLargoConUnaJornada(): array
{
    $site = WorkforceFixtures::site('Hotel del diferido', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site);

    $workDay = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $workDay->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    app(WorkDayRepository::class)->save($workDay);

    return ['site' => $site, 'employee' => $employee];
}

/**
 * La exportacion legal de marzo, con lo que la prueba quiera añadir.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function diferidoLargoCuerpoLegal(array $extra = []): array
{
    return ['kind' => 'legal', 'format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31', ...$extra];
}

/**
 * Los asientos de una accion, con su actor y su cuerpo ya decodificado.
 *
 * @return list<array{actor_type: string, actor_id: int|null, payload: array<string, mixed>}>
 */
function diferidoLargoAsientos(string $action): array
{
    return array_values(DB::table('audit_log')
        ->where('action', $action)
        ->orderBy('id')
        ->get(['actor_type', 'actor_id', 'payload'])
        ->map(static fn (object $row): array => [
            'actor_type' => AuditPayloads::text($row->actor_type),
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'payload' => AuditPayloads::decode($row->payload),
        ])
        ->all());
}

function diferidoLargoTablero(): RecordingReportExportProgressBoard
{
    $board = new RecordingReportExportProgressBoard;
    app()->instance(ReportExportProgressBoard::class, $board);

    return $board;
}

it('el auditor pide en diferido la exportacion para la Inspeccion, tambien sin licencia', function (): void {
    // Regla dura 15: el registro legal no se degrada jamas. Sin `grantAll()`.
    Queue::fake();
    WorkforceFixtures::site();

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)))
        ->post('/api/v1/reports/exports', diferidoLargoCuerpoLegal())
        ->assertValidRequest()
        ->assertValidResponse(202);

    expect($response->json('data.kind'))->toBe('legal')
        ->and($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.parameters.granularity'))->toBeNull()
        ->and($response->json('data.parameters.group_by'))->toBeNull()
        ->and($response->json('data.progress'))->toBeNull()
        ->and($response->json('data.cancelled_at'))->toBeNull()
        ->and(diferidoLargoAsientos('report_export.requested'))->toHaveCount(1);

    Queue::assertPushed(GenerateReportExportJob::class);
})->group('RF-IN-05', 'RF-IN-06', 'RL-06', 'RS-05');

it('la exportacion legal no tiene techo de dias, como la sincrona', function (): void {
    Queue::fake();
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', diferidoLargoCuerpoLegal(['from' => '2022-11-01']))
        ->assertValidResponse(202);
})->group('RF-IN-05', 'RL-02');

it('rechaza en la exportacion legal un filtro o un formato que no admite', function (array $extra, string $campo): void {
    // Un filtro ignorado en silencio dejaria a quien atiende el requerimiento
    // convencido de haber acotado la exportacion.
    Queue::fake();
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', diferidoLargoCuerpoLegal($extra))
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor($campo);
})->with([
    'granularidad' => [['granularity' => 'day'], 'granularity'],
    'agrupacion' => [['group_by' => 'employee'], 'group_by'],
    'departamento' => [['department_id' => 1], 'department_id'],
    'turnos abiertos' => [['include_open_shifts' => true], 'include_open_shifts'],
    'XLSX' => [['format' => 'xlsx'], 'format'],
    'periodo invertido' => [['from' => '2026-04-01'], 'to'],
])->group('RF-IN-05', 'RL-06');

it('el auditor no pide en diferido ningun otro informe', function (string $kind): void {
    LicenseKeys::grantAll();
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)))
        ->post('/api/v1/reports/exports', ['kind' => $kind, 'format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertStatus(403);
})->with(['period', 'payroll', 'adoption'])->group('RF-ID-02', 'RQ-07', 'RS-05');

it('un token sin reports:* no pide un informe de horas aunque el rol lo permita', function (): void {
    // El ambito se comprueba ademas del rol (doc 02 §7.3): la ruta acepta
    // `reports:legal`, y eso no puede regalar el informe de horas.
    Queue::fake();
    LicenseKeys::grantAll();
    WorkforceFixtures::site();

    /** @var User $rrhh */
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $estrecho = $rrhh->createToken('Panel de gestion', ['reports:legal'])->plainTextToken;

    Api::as($estrecho)
        ->post('/api/v1/reports/exports', ['kind' => 'period', 'format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertStatus(403);

    Api::as($estrecho)->post('/api/v1/reports/exports', diferidoLargoCuerpoLegal())->assertStatus(202);
})->group('RS-05', 'RQ-07');

it('genera la exportacion legal con el mismo fichero que la sincrona y su asiento a nombre de quien la pidio', function (): void {
    $contexto = diferidoLargoConUnaJornada();
    $auditor = ManagementUsers::withRole(UserRole::AUDITOR);
    $board = diferidoLargoTablero();

    $export = ReportExports::pendingFor(
        $auditor->id,
        kind: ReportExportKind::Legal,
        parameters: ReportExportParameters::span('2026-03-01', '2026-03-31'),
    );

    $generada = app(GenerateReportExportHandler::class)->handle($export->uuid);

    expect($generada->status)->toBe(ReportExportStatus::Completed)
        ->and($generada->fileName)->toBe('registro-horario-2026-03-01_2026-03-31.csv')
        ->and($generada->rowCount)->toBe(1);

    $csv = (string) file_get_contents((string) $generada->filePath);

    // El mismo documento que `GET /reports/legal-export`: BOM, cabecera de
    // criterios, el tramo en hora del centro y las horas como `HH:MM`.
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Criterios de inclusion')
        ->and($csv)->toContain('TRAMO;')
        ->and($csv)->toContain('2026-03-14 07:00')
        ->and($csv)->toContain('08:00');

    $legal = diferidoLargoAsientos('legal_export.generated');

    expect($legal)->toHaveCount(1)
        ->and($legal[0]['actor_type'])->toBe('user')
        ->and($legal[0]['actor_id'])->toBe($auditor->id)
        ->and($legal[0]['payload'])->toMatchArray([
            'period_from' => '2026-03-01',
            'period_to' => '2026-03-31',
            'scope' => 'all',
            'shift_entry_rows' => 1,
            'correction_rows' => 0,
            'employees_exported' => 1,
            'report_export_uuid' => $export->uuid,
            'deferred' => true,
        ])
        ->and(diferidoLargoAsientos('report_export.generated')[0]['actor_id'] ?? null)->toBe($auditor->id)
        // Como la sincrona: el asiento legal ya dice quien, que y cuanto.
        ->and(diferidoLargoAsientos('personal_data.accessed'))->toBe([]);

    // Leyendo, escribiendo con porcentaje medido, cerrando; y al terminar el
    // avance se retira.
    expect($board->stages())->toBe(['reading', 'writing', 'sealing'])
        ->and(array_column($board->recorded, 'percent'))->toContain(99)
        ->and($board->forgotten)->toBe([$export->uuid]);

    expect($contexto['employee'])->toBeString();
})->group('RF-IN-05', 'RF-IN-06', 'RL-06', 'RS-05', 'RL-15');

it('la exportacion legal de una persona solo lleva a esa persona', function (): void {
    $contexto = diferidoLargoConUnaJornada();
    $otra = WorkforceFixtures::employee($contexto['site']);

    $export = ReportExports::pendingFor(
        ManagementUsers::withRole(UserRole::RRHH)->id,
        kind: ReportExportKind::Legal,
        parameters: ReportExportParameters::span('2026-03-01', '2026-03-31', $otra),
    );

    $generada = app(GenerateReportExportHandler::class)->handle($export->uuid);

    expect($generada->rowCount)->toBe(0)
        ->and(diferidoLargoAsientos('legal_export.generated')[0]['payload'] ?? [])->toMatchArray([
            'scope' => 'employee',
            'employee_uuid' => $otra,
        ]);
})->group('RF-IN-05', 'RS-05');

it('el cuadro de impacto en diferido exige su licencia', function (): void {
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', ['kind' => 'adoption', 'format' => 'pdf', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertValidResponse(402);
})->group('RF-IN-08', 'RF-PD-05');

it('rechaza en el cuadro de impacto la persona y los filtros del informe', function (array $extra, string $campo): void {
    Queue::fake();
    LicenseKeys::grantAll();
    WorkforceFixtures::site();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', [
            'kind' => 'adoption', 'format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31', ...$extra,
        ])
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor($campo);
})->with([
    'persona' => [['employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'], 'employee_uuid'],
    'granularidad' => [['granularity' => 'month'], 'granularity'],
    'mas de un año' => [['from' => '2025-01-01'], 'from'],
])->group('RF-IN-08');

it('genera el cuadro de impacto con su asiento de documento y sin lectura de datos personales', function (): void {
    LicenseKeys::grantAll();
    WorkforceFixtures::site();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $export = ReportExports::pendingFor(
        $rrhh->id,
        kind: ReportExportKind::Adoption,
        parameters: ReportExportParameters::span('2026-03-01', '2026-03-31'),
    );

    $generada = app(GenerateReportExportHandler::class)->handle($export->uuid);

    expect($generada->status)->toBe(ReportExportStatus::Completed)
        ->and($generada->fileName)->toBe('kronoqr-adopcion-2026-03-01_2026-03-31.csv')
        ->and($generada->criteria)->not->toBe([]);

    $documento = diferidoLargoAsientos('adoption_report.exported');

    expect($documento)->toHaveCount(1)
        ->and($documento[0]['actor_id'])->toBe($rrhh->id)
        ->and($documento[0]['payload'])->toMatchArray([
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'format' => 'csv',
            'sha256' => $generada->sha256,
            'size_bytes' => $generada->sizeBytes,
        ])
        // Un agregado sin personas (regla dura 21): ninguna lectura que contar.
        ->and(diferidoLargoAsientos('personal_data.accessed'))->toBe([]);
})->group('RF-IN-08', 'RS-05');

it('cancela una pendiente, libera el turno de esa persona y deja asiento', function (): void {
    Queue::fake();
    WorkforceFixtures::site();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($rrhh);

    $uuid = Api::as($token)->post('/api/v1/reports/exports', diferidoLargoCuerpoLegal())->json('data.uuid');
    expect($uuid)->toBeString();
    $uuid = \is_string($uuid) ? $uuid : '';

    $response = Api::as($token)
        ->post('/api/v1/reports/exports/'.$uuid.'/cancel')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.status'))->toBe('cancelled')
        ->and($response->json('data.cancelled_at'))->toBeString()
        ->and($response->json('data.download'))->toBeNull();

    $asientos = diferidoLargoAsientos('report_export.cancelled');

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_id'])->toBe($rrhh->id)
        ->and($asientos[0]['payload'])->toMatchArray([
            'report_export_uuid' => $uuid,
            'kind' => 'legal',
            'previous_status' => 'pending',
        ]);

    // El indice unico parcial ya no la cuenta: la siguiente no es `409`.
    Api::as($token)->post('/api/v1/reports/exports', diferidoLargoCuerpoLegal())->assertStatus(202);
})->group('RF-IN-06', 'RS-05');

it('cancelar dos veces devuelve la misma fila sin un segundo asiento', function (): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $export = ReportExports::pendingFor($rrhh->id);
    $token = ManagementUsers::tokenFor($rrhh);

    $primera = Api::as($token)->post('/api/v1/reports/exports/'.$export->uuid.'/cancel')->assertOk();
    $segunda = Api::as($token)->post('/api/v1/reports/exports/'.$export->uuid.'/cancel')->assertValidResponse(200);

    expect($segunda->json('data.cancelled_at'))->toBe($primera->json('data.cancelled_at'))
        ->and(diferidoLargoAsientos('report_export.cancelled'))->toHaveCount(1);
})->group('RF-IN-06');

it('no cancela una exportacion que ya termino: 409 con la fila, y el fichero sigue', function (): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $terminada = ReportExports::completedFor($rrhh->id);

    $response = Api::as(ManagementUsers::tokenFor($rrhh))
        ->post('/api/v1/reports/exports/'.$terminada->uuid.'/cancel')
        ->assertValidResponse(409);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:report-export-not-cancellable')
        ->and($response->json('export.status'))->toBe('completed')
        ->and(is_file((string) $terminada->filePath))->toBeTrue()
        ->and(diferidoLargoAsientos('report_export.cancelled'))->toBe([]);
})->group('RF-IN-06');

it('la exportacion de otra persona no se cancela: 404 y no 403', function (): void {
    $ajena = ReportExports::pendingFor(ManagementUsers::withRole(UserRole::RRHH)->id);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/reports/exports/'.$ajena->uuid.'/cancel')
        ->assertValidResponse(404);

    expect(app(ReportExportRepository::class)->statusOf($ajena->uuid))->toBe(ReportExportStatus::Pending);
})->group('RF-IN-06', 'RF-ID-03');

it('una exportacion cancelada antes de arrancar no genera nada', function (): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $export = ReportExports::pendingFor($rrhh->id);

    app(CancelReportExport::class)->handle($export->uuid, $rrhh->id);

    $resultado = app(GenerateReportExportHandler::class)->handle($export->uuid);

    expect($resultado->status)->toBe(ReportExportStatus::Cancelled)
        ->and($resultado->startedAt)->toBeNull()
        ->and(glob(ReportExports::useTemporaryPath().'/'.$export->uuid.'/*') ?: [])->toBe([])
        ->and(diferidoLargoAsientos('report_export.generated'))->toBe([]);
})->group('RF-IN-06');

it('cancelada mientras se escribe, borra el fichero a medias y no publica la generacion', function (): void {
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $export = ReportExports::pendingFor(
        $rrhh->id,
        kind: ReportExportKind::Legal,
        parameters: ReportExportParameters::span('2026-03-01', '2026-03-31'),
    );
    /** @var ArrayObject<int, string> $escrito */
    $escrito = new ArrayObject;

    // Escribe media exportacion y, a mitad, alguien pulsa «Cancelar». El aviso
    // de avance siguiente es el punto de cancelacion.
    app()->instance(LegalRecordFileWriter::class, new class($export->uuid, $rrhh->id, $escrito) implements LegalRecordFileWriter
    {
        /** @param ArrayObject<int, string> $escrito */
        public function __construct(private string $uuid, private int $userId, private ArrayObject $escrito) {}

        public function write(string $from, string $to, ?string $employeeUuid, string $destinationPath, Closure $onProgress): LegalRecordFile
        {
            file_put_contents($destinationPath, "media exportacion\n");
            $this->escrito->append($destinationPath);

            $onProgress(0, 1000);
            app(CancelReportExport::class)->handle($this->uuid, $this->userId);
            $onProgress(500, 1000);

            return new LegalRecordFile('registro-horario-x.csv', $from, $to, null, 1000, 0, 10);
        }

        public function fileNameFor(string $from, string $to): string
        {
            return 'registro-horario-'.$from.'_'.$to.'.csv';
        }
    });

    $resultado = app(GenerateReportExportHandler::class)->handle($export->uuid);

    expect($resultado->status)->toBe(ReportExportStatus::Cancelled)
        ->and($escrito->count())->toBe(1)
        ->and(file_exists((string) $escrito[0]))->toBeFalse('El fichero a medias se quedo en el disco.')
        ->and(diferidoLargoAsientos('report_export.generated'))->toBe([])
        ->and(diferidoLargoAsientos('legal_export.generated'))->toBe([])
        ->and(diferidoLargoAsientos('report_export.cancelled')[0]['payload']['previous_status'] ?? null)->toBe('running');
})->group('RF-IN-06', 'RS-05');

it('la lista enseña el avance de las que se estan generando y de ninguna otra', function (): void {
    $board = diferidoLargoTablero();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    $exports = app(ReportExportRepository::class);
    $now = app(Clock::class)->now();

    $cancelada = ReportExports::pendingFor($rrhh->id);
    app(CancelReportExport::class)->handle($cancelada->uuid, $rrhh->id);
    // Un avance anotado antes de que el trabajador se enterara de la cancelacion.
    $board->record($cancelada->uuid, ReportExportProgress::at(ReportExportStage::Reading, $now));

    $enCurso = ReportExports::pendingFor($rrhh->id)->start($now);
    $exports->save($enCurso);
    $board->record($enCurso->uuid, ReportExportProgress::ofRows(ReportExportStage::Writing, 250, 1000, $now));

    $response = Api::as(ManagementUsers::tokenFor($rrhh))
        ->get('/api/v1/reports/exports')
        ->assertValidResponse(200);

    $porUuid = collect((array) $response->json('data'))->keyBy('uuid');

    expect($porUuid[$enCurso->uuid]['progress'] ?? null)->toMatchArray(['stage' => 'writing', 'percent' => 25])
        ->and($porUuid[$cancelada->uuid])->toHaveKey('progress')
        ->and($porUuid[$cancelada->uuid]['progress'] ?? null)->toBeNull()
        ->and($porUuid[$cancelada->uuid]['status'] ?? null)->toBe('cancelled');

    // Y la consulta de una sola, que tampoco acuña enlace mientras corre.
    $una = Api::as(ManagementUsers::tokenFor($rrhh))
        ->get('/api/v1/reports/exports/'.$enCurso->uuid)
        ->assertValidResponse(200);

    expect($una->json('data.progress.percent'))->toBe(25)
        ->and($una->json('data.download'))->toBeNull();
})->group('RF-IN-06');

it('el auditor ve, consulta y cancela las suyas', function (): void {
    $auditor = ManagementUsers::withRole(UserRole::AUDITOR);
    $suya = ReportExports::pendingFor(
        $auditor->id,
        kind: ReportExportKind::Legal,
        parameters: ReportExportParameters::span('2026-03-01', '2026-03-31'),
    );
    $token = ManagementUsers::tokenFor($auditor);

    expect(Api::as($token)->get('/api/v1/reports/exports')->assertValidResponse(200)->json('data.0.uuid'))->toBe($suya->uuid);

    Api::as($token)->get('/api/v1/reports/exports/'.$suya->uuid)->assertValidResponse(200);
    Api::as($token)->post('/api/v1/reports/exports/'.$suya->uuid.'/cancel')->assertValidResponse(200);
})->group('RF-ID-02', 'RF-IN-05', 'RQ-07');
