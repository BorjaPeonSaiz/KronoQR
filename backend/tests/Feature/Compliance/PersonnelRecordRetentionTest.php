<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseAuditTrail;
use App\Modules\Product\Domain\ValueObject\DataExportCatalog;
use App\Modules\Product\Infrastructure\Persistence\DatabaseDataExportSource;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `compliance:apply-retention` minimiza las fichas vencidas de personas dadas
 * de baja (ADR-060, tarea 11.3 A; RL-11, RL-02, RL-04, RL-08, RS-05): la
 * simulacion las cuenta sin uuids ni nombres; la ejecucion, firmada por admin o
 * rrhh, deja un asiento por persona en la misma transaccion y borra la foto
 * despues; si el asiento falla no se minimiza nada; y despues el directorio y
 * la exportacion integra enseñan la ficha minimizada y el portal da el rechazo
 * generico con el codigo antiguo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $directory = sys_get_temp_dir().'/kronoqr-personnel-retention-'.bin2hex(random_bytes(6));

    Config::set('compliance.retention.report_path', $directory.'/informes');
    Config::set('compliance.retention.technical_log_path', $directory.'/logs');
    Config::set('observability.metrics.textfile_path', $directory.'/metricas');
    app()->setLocale('es');
    Config::set('compliance.retention.personnel_photo_root', $directory.'/fotos-ficha');
    @mkdir($directory.'/fotos-ficha/fotos', 0700, true);

    WorkforceFixtures::site();

    /** @var object{today: string} $row */
    $row = DB::selectOne("SELECT ((now() AT TIME ZONE 'UTC')::date)::text AS today");
    FrozenTime::at($row->today.' 04:00:00');
});

/**
 * Una persona dada de baja en 2015 con foto, un contrato y PIN.
 *
 * @return array{id: int, uuid: string, code: string}
 */
function fichaVencida(): array
{
    $code = 'E'.Str::upper(Str::random(9));
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId(), status: 'terminated', firstName: 'Lucia', lastName: 'Ferrer', employeeCode: $code);
    $id = DB::table('employees')->where('uuid', $uuid)->value('id');
    $id = \is_int($id) ? $id : 0;

    DB::table('employees')->where('id', $id)->update([
        'hired_at' => '2010-01-01',
        'terminated_at' => '2015-06-30',
        'email' => 'lucia@hotel.example',
        'photo_path' => 'fotos/'.$uuid.'.jpg',
    ]);
    DB::table('employment_contracts')->insert([
        'employee_id' => $id,
        'weekly_hours' => 40,
        'schedule_type' => 'continua',
        'valid_from' => '2010-01-01',
        'created_at' => '2010-01-01 00:00:00+00',
    ]);
    file_put_contents(fichaFoto($uuid), 'jpeg');

    return ['id' => $id, 'uuid' => $uuid, 'code' => $code];
}

/**
 * @return array{code: int, output: string}
 */
function retencionDeFichas(bool $simular): array
{
    $responsable = ManagementUsers::withRole(UserRole::RRHH);

    $code = Artisan::call('compliance:apply-retention', $simular ? ['--dry-run' => true] : [
        '--confirm' => app(PlanRetention::class)->handle(app(Clock::class)->now())->confirmationToken(),
        '--responsible' => $responsable->email,
        '--responsible-confirm' => $responsable->email,
        '--no-interaction' => true,
    ]);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('la simulacion cuenta las fichas vencidas sin uuids ni nombres y no toca nada', function (): void {
    $ficha = fichaVencida();

    $orden = retencionDeFichas(true);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Fichas de personal')
        ->and($orden['output'])->not->toContain($ficha['uuid'])
        ->and($orden['output'])->not->toContain('Lucia')
        ->and(DB::table('employees')->where('id', $ficha['id'])->value('anonymized_at'))->toBeNull();
})->group('RL-11', 'RF-PR-03');

it('la ejecucion minimiza la ficha, deja un asiento por persona y borra la foto despues', function (): void {
    $ficha = fichaVencida();

    $orden = retencionDeFichas(false);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and(DB::table('employees')->where('id', $ficha['id'])->value('first_name'))->toBe('')
        ->and(file_exists(fichaFoto($ficha['uuid'])))->toBeFalse();

    $asientos = DB::table('audit_log')->where('action', AuditAction::RetentionPersonnelRecordAnonymized->value)->get();

    expect($asientos)->toHaveCount(1);

    /** @var object{actor_type: string, subject_type: string, subject_id: int|null, payload: string} $asiento */
    $asiento = $asientos->first();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        ->and($asiento->subject_type)->toBe('employee')
        ->and($asiento->subject_id)->toBeNull()
        ->and($payload)->toMatchArray([
            'employee_uuid' => $ficha['uuid'],
            'terminated_on' => '2015-06-30',
            'personnel_record_retention_years' => 4,
            'retention_years' => 4,
            'retention_floor_years' => 4,
            'jurisdiction' => 'ES',
            'contracts_deleted' => 1,
            'absences_deleted' => 0,
            'tokens_deleted' => 0,
        ])
        ->and($payload)->toHaveKeys(['cutoff_date', 'site_id', 'fields', 'confirmation'])
        ->and($asiento->payload)->not->toContain('Lucia')
        ->and($asiento->payload)->not->toContain($ficha['code'])
        ->and($asiento->payload)->not->toContain('fotos/');
})->group('RL-11', 'RL-04', 'RL-08', 'RS-05', 'RF-PR-03');

it('no minimiza en la misma pasada la ficha que libera su propia purga: espera a la siguiente', function (): void {
    $ficha = fichaVencida();
    // Su jornada vencida la retiene: la simulacion no la cuenta.
    DB::table('daily_totals')->insert([
        'employee_id' => $ficha['id'],
        'work_date' => '2015-03-30',
        'total_minutes' => 480,
        'shift_count' => 1,
        'recalculated_at' => '2015-03-30 23:00:00+00',
    ]);

    $simulada = retencionDeFichas(true);
    $primera = retencionDeFichas(false);

    // La purga de esta pasada se lleva la jornada, pero la ficha no la conto
    // el plan: sigue entera.
    expect($simulada['code'])->toBe(0, $simulada['output'])
        ->and($primera['code'])->toBe(0, $primera['output'])
        ->and(DB::table('daily_totals')->where('employee_id', $ficha['id'])->count())->toBe(0)
        ->and(DB::table('employees')->where('id', $ficha['id'])->value('first_name'))->toBe('Lucia')
        ->and(DB::table('audit_log')->where('action', AuditAction::RetentionPersonnelRecordAnonymized->value)->count())->toBe(0);

    $segunda = retencionDeFichas(false);

    expect($segunda['code'])->toBe(0, $segunda['output'])
        ->and(DB::table('employees')->where('id', $ficha['id'])->value('first_name'))->toBe('')
        ->and(DB::table('audit_log')->where('action', AuditAction::RetentionPersonnelRecordAnonymized->value)->count())->toBe(1);
})->group('RL-11', 'RF-PR-03');

it('si el asiento falla, no se minimiza ninguna ficha ni se borra su foto', function (): void {
    $ficha = fichaVencida();

    app()->instance(AuditTrail::class, new class(new DatabaseAuditTrail(DB::connection())) implements AuditTrail
    {
        public function __construct(private readonly AuditTrail $trail) {}

        public function append(AuditEntryDraft $draft): AuditEntry
        {
            if ($draft->action->value === AuditAction::RetentionPersonnelRecordAnonymized->value) {
                throw new RuntimeException('audit_log no responde');
            }

            return $this->trail->append($draft);
        }
    });

    expect(static fn (): array => retencionDeFichas(false))->toThrow(RuntimeException::class, 'audit_log no responde');

    expect(DB::table('employees')->where('id', $ficha['id'])->value('first_name'))->toBe('Lucia')
        ->and(DB::table('employment_contracts')->where('employee_id', $ficha['id'])->count())->toBe(1)
        ->and(file_exists(fichaFoto($ficha['uuid'])))->toBeTrue();
})->group('RL-11', 'RL-04');

it('una foto que no se puede borrar queda en el log con el employee_uuid y en el informe', function (): void {
    $ficha = fichaVencida();
    // Un directorio donde se espera la foto: `unlink` no lo borra.
    unlink(fichaFoto($ficha['uuid']));
    mkdir(fichaFoto($ficha['uuid']));

    /** @var list<array{message: string, context: array<string, mixed>}> $mensajes */
    $mensajes = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$mensajes): void {
        $mensajes[] = ['message' => $message->message, 'context' => $message->context];
    });

    $orden = retencionDeFichas(false);

    $huerfanas = array_values(array_filter($mensajes, static fn (array $m): bool => $m['message'] === 'retention.personnel_photo_orphaned'));

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('no se pudieron borrar')
        ->and($huerfanas)->toHaveCount(1)
        ->and($huerfanas[0]['context']['employee_uuid'] ?? null)->toBe($ficha['uuid'])
        ->and(json_encode($huerfanas[0]['context']))->not->toContain('fotos/');
})->group('RL-11', 'RL-08');

it('despues el directorio y la exportacion integra enseñan la ficha minimizada', function (): void {
    Spectator::using('openapi.yaml');
    $ficha = fichaVencida();
    retencionDeFichas(false);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/employees/'.$ficha['uuid'])
        ->assertValidResponse(200)
        ->assertJsonPath('first_name', '')
        ->assertJsonPath('last_name', '')
        ->assertJsonPath('email', null)
        ->assertJsonPath('employee_code', strtoupper(str_replace('-', '', $ficha['uuid'])))
        ->assertJsonPath('status', 'terminated')
        ->assertJsonPath('anonymized_at', static fn (mixed $value): bool => \is_string($value) && str_ends_with($value, 'Z'));

    $filas = iterator_to_array((new DatabaseDataExportSource(DB::connection()))->rows(DataExportCatalog::dataset('employees') ?? throw new LogicException('Sin conjunto employees.')), false);
    $fila = collect($filas)->first(static fn (array $row): bool => ($row['employee_uuid'] ?? null) === $ficha['uuid']);

    expect($fila)->toBeArray()
        ->and($fila['first_name'] ?? null)->toBe('')
        ->and($fila['anonymized_at'] ?? null)->not->toBeNull();
})->group('RL-11', 'RL-08', 'RL-20');

it('una ficha minimizada no admite ausencias ni contratos: 409 con el motivo traducido', function (string $locale, string $texto): void {
    Spectator::using('openapi.yaml');
    $ficha = fichaVencida();
    retencionDeFichas(false);
    $minimizada = DB::table('employees')->where('id', $ficha['id'])->value('anonymized_at');
    $fecha = substr(\is_string($minimizada) ? $minimizada : '', 0, 10);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $ausencia = Api::as($token)->withHeaders(['Accept-Language' => $locale])->post('/api/v1/absences', [
        'employee_uuid' => $ficha['uuid'],
        'type' => 'vacation',
        'starts_on' => '2015-05-04',
        'ends_on' => '2015-05-08',
    ]);
    $contrato = Api::as($token)->withHeaders(['Accept-Language' => $locale])->post('/api/v1/employees/'.$ficha['uuid'].'/contracts', [
        'weekly_hours' => 40,
        'schedule_type' => 'turnos',
        'valid_from' => '2015-01-01',
    ]);

    foreach ([$ausencia, $contrato] as $respuesta) {
        $respuesta->assertValidResponse(409);

        expect($respuesta->json('detail'))->toBe(str_replace(':anonymized_on', $fecha, $texto));
    }

    expect(DB::table('absences')->where('employee_id', $ficha['id'])->count())->toBe(0)
        ->and(DB::table('employment_contracts')->where('employee_id', $ficha['id'])->count())->toBe(0);
})->with([
    'en español' => ['es', 'La ficha de esta persona se minimizó el :anonymized_on al vencer su plazo de conservación: ya no admite contratos ni ausencias.'],
])->group('RL-11', 'RF-GP-02', 'RF-GP-04');

it('el portal da el rechazo generico con el codigo antiguo', function (): void {
    $ficha = fichaVencida();
    retencionDeFichas(false);

    Api::guest()->post('/api/v1/me/login', ['employee_code' => $ficha['code'], 'pin' => '123456'])
        ->assertStatus(401);
})->group('RL-11', 'RS-03');

/** La ruta absoluta de la foto de la ficha, en el directorio de la prueba. */
function fichaFoto(string $uuid): string
{
    return Config::string('compliance.retention.personnel_photo_root').'/fotos/'.$uuid.'.jpg';
}
