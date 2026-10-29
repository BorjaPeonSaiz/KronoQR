<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA BAJA PROGRAMADA DESDE FUERA** (RN-14, RF-GP-03, ADR-062 §3 y §10).
 *
 * Reprogramar y anular por la API, con sus asientos y sus `409`; y la regla que
 * no depende de la tarea: desde que hay fecha de cese, ningun tramo nuevo cae en
 * una jornada posterior, ni por el quiosco ni por el panel, aunque la baja no se
 * haya aplicado todavia. El ultimo turno de noche y la vuelta de su pausa siguen
 * siendo de la jornada del cese.
 *
 * El centro esta en Madrid (UTC+1 a finales de octubre, tras el cambio de hora
 * del 25). La persona tiene la baja programada con cese el 30 de octubre.
 */

uses(RefreshDatabase::class);

const SCHEDULED_OFFBOARDING_LAST_DAY = '2026-10-30';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function tokenDeLaBajaProgramada(UserRole $role = UserRole::RRHH): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole($role));
}

/**
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, card: string}
 */
function escenarioDeLaBajaProgramada(): array
{
    $escenario = AttendanceFixtures::scenario();
    $card = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']))->toString();

    return [...$escenario, 'card' => $card];
}

function programarLaBaja(string $employee, string $cese = SCHEDULED_OFFBOARDING_LAST_DAY): void
{
    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$employee.'/offboard', ['terminated_at' => $cese])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.state', 'scheduled');
}

/**
 * @return TestResponse<Response>
 */
function ficharConLaBajaProgramada(string $deviceToken, string $card, string $occurredAt): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($deviceToken)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => $occurredAt, 'qr_payload' => $card]);
}

/**
 * @return list<array<string, mixed>>
 */
function asientosDeLaBajaProgramada(string $action): array
{
    return array_values(DB::table('audit_log')->where('action', $action)->orderBy('id')->get()
        ->map(static function (object $row): array {
            /** @var object{payload: string} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return $payload;
        })->values()->all());
}

// --- Reprogramar -------------------------------------------------------------

it('reprograma con otra fecha y deja la anterior en el asiento; la misma fecha no deja nada', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-11-15'])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.terminated_at', '2026-11-15');

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-11-15'])
        ->assertValidResponse(200);

    expect(asientosDeLaBajaProgramada('employee.offboarding_scheduled'))->toEqual([
        ['employee_uuid' => $escenario['employee'], 'terminated_on' => SCHEDULED_OFFBOARDING_LAST_DAY, 'has_reason' => false],
        [
            'employee_uuid' => $escenario['employee'],
            'terminated_on' => '2026-11-15',
            'previous_terminated_on' => SCHEDULED_OFFBOARDING_LAST_DAY,
            'has_reason' => false,
        ],
    ]);
})->group('RN-14', 'RF-GP-03', 'RL-04');

it('aplica ya una baja programada con el cese de hoy: revoca la tarjeta y deja employee.offboarded', function (): void {
    FrozenTime::at('2026-10-30 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => SCHEDULED_OFFBOARDING_LAST_DAY, 'apply_now' => true])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated')
        ->assertJsonPath('offboarding.state', 'effective');

    expect(DB::table('credentials')->whereNull('revoked_at')->count())->toBe(0)
        ->and(asientosDeLaBajaProgramada('employee.offboarded'))->toEqual([[
            'employee_uuid' => $escenario['employee'],
            'terminated_on' => SCHEDULED_OFFBOARDING_LAST_DAY,
            'has_reason' => false,
            'apply_now' => true,
        ]]);
})->group('RN-14', 'RF-GP-03', 'RF-QR-03');

it('no cambia la fecha de una baja programada cuyo cese ya paso', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);
    FrozenTime::at('2026-10-31 10:00:00');

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => '2026-11-15'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:offboarding-due');

    expect(DB::table('employees')->where('uuid', $escenario['employee'])->value('terminated_at'))->toBe(SCHEDULED_OFFBOARDING_LAST_DAY);
})->group('RN-14', 'RF-GP-03');

// --- Anular ------------------------------------------------------------------

it('anula la baja programada hasta el propio dia del cese, con su asiento', function (string $ahora): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);
    FrozenTime::at($ahora);

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard/cancel')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('terminated_at', null)
        ->assertJsonPath('offboarding', null);

    expect(asientosDeLaBajaProgramada('employee.offboarding_cancelled'))->toEqual([[
        'employee_uuid' => $escenario['employee'],
        'terminated_on' => SCHEDULED_OFFBOARDING_LAST_DAY,
    ]])
        ->and(DB::table('credentials')->whereNull('revoked_at')->count())->toBe(1);
})->with([
    'antes del cese' => ['2026-10-29 10:00:00'],
    // 22:30 UTC del 30 son las 23:30 del 30 en Madrid: aun es el dia del cese.
    'el dia del cese, a las 23:30 en Madrid' => ['2026-10-30 22:30:00'],
])->group('RN-14', 'RF-GP-03', 'RL-04');

it('no anula pasada la fecha de cese, aunque la tarea no la haya aplicado', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);
    // 23:30 UTC del 30 son las 00:30 del 31 en Madrid.
    FrozenTime::at('2026-10-30 23:30:00');
    $asientos = DB::table('audit_log')->count();

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard/cancel')
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:offboarding-due')
        ->assertJsonPath('detail', 'La fecha de cese (2026-10-30) ya ha pasado (hoy es 2026-10-31). La baja ya rige y no se puede anular.');

    expect(DB::table('audit_log')->count())->toBe($asientos)
        ->and(DB::table('employees')->where('uuid', $escenario['employee'])->value('terminated_at'))->toBe(SCHEDULED_OFFBOARDING_LAST_DAY);
})->group('RN-14', 'RF-GP-03');

it('no anula lo que no es una baja programada', function (string $estado, ?string $cese, string $tipo): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $site = WorkforceFixtures::site('Hotel de la anulacion');
    $persona = WorkforceFixtures::employee($site, null, $estado);

    if ($cese !== null) {
        DB::table('employees')->where('uuid', $persona)->update(['terminated_at' => $cese]);
    }

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$persona.'/offboard/cancel')
        ->assertValidResponse(409)
        ->assertJsonPath('type', $tipo);

    expect(DB::table('audit_log')->where('action', 'employee.offboarding_cancelled')->count())->toBe(0);
})->with([
    'sin fecha de cese' => ['active', null, 'urn:kronoqr:problem:offboarding-not-scheduled'],
    'con la baja efectiva' => ['terminated', null, 'urn:kronoqr:problem:employee-terminated'],
])->group('RN-14', 'RF-GP-03');

it('la anulacion no admite cuerpo, ni personas que no existen, ni una instalacion sin centro', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/offboard/cancel')
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');

    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard/cancel', ['terminated_at' => '2026-10-30'])
        ->assertValidResponse(422);

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91/offboard/cancel')
        ->assertValidResponse(404);
})->group('RN-14', 'RF-GP-03');

// --- La ficha ----------------------------------------------------------------

it('enseña la baja programada a rrhh y al responsable, que ya ve el estado y la fecha', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);

    Api::as(tokenDeLaBajaProgramada())
        ->get('/api/v1/employees/'.$escenario['employee'])
        ->assertValidResponse(200)
        ->assertJsonPath('offboarding.state', 'scheduled');

    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    Department::query()->whereKey($escenario['department'])->update(['manager_user_id' => $responsable->id]);

    $vista = Api::as(ManagementUsers::tokenFor($responsable))
        ->get('/api/v1/employees/'.$escenario['employee'])
        ->assertValidResponse(200)
        ->assertJsonPath('terminated_at', SCHEDULED_OFFBOARDING_LAST_DAY)
        ->assertJsonPath('offboarding.state', 'scheduled');

    expect(array_key_exists('pin_status', (array) $vista->json()))->toBeFalse();
})->group('RN-14', 'RF-ID-03');

// --- Ningun tramo nuevo despues del cese -------------------------------------

it('ficha hasta su ultimo dia y rechaza con el rechazo generico la entrada del dia siguiente', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);
    FrozenTime::at('2026-10-31 08:00:00');

    // El ultimo dia, entrada y salida: su jornada es la del cese.
    ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-30T07:00:00Z')->assertOk();
    ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-30T15:00:00Z')->assertOk();

    // El dia siguiente: la tarea aun no ha pasado y la persona sigue en alta.
    $entrada = ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-31T07:30:00Z');
    $inexistente = ficharConLaBajaProgramada($escenario['token'], Credentials::signedWithUnknownKey()->toString(), '2026-10-31T07:31:00Z');

    $entrada->assertStatus(422)->assertValidResponse(422);
    $cuerpo = (array) $entrada->json();
    $otro = (array) $inexistente->json();
    unset($cuerpo['scan_id'], $otro['scan_id']);

    $fila = DB::table('scan_events')->where('scan_id', $entrada->json('scan_id'))->first();

    expect($cuerpo)->toBe($otro)
        ->and($fila?->result)->toBe('rejected_unknown')
        ->and($fila?->employee_id)->toBe(AttendanceFixtures::employeeIdOf($escenario['employee']))
        ->and($fila?->flagged_for_review)->toBeFalse()
        ->and(DB::table('shift_entries')->where('work_date', '>', SCHEDULED_OFFBOARDING_LAST_DAY)->count())->toBe(0)
        ->and(DB::table('employees')->where('uuid', $escenario['employee'])->value('status'))->toBe('active');
})->group('RN-14', 'RS-03', 'RF-AT-02');

it('cierra el turno de noche del ultimo dia con su salida de madrugada', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);
    FrozenTime::at('2026-10-31 06:00:00');

    // 21:00 UTC del 30 son las 22:00 del 30 en Madrid; 05:00 UTC del 31, las 06:00.
    ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-30T21:00:00Z')->assertOk();
    ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-31T05:00:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'clock_out')
        ->assertJsonPath('work_date', SCHEDULED_OFFBOARDING_LAST_DAY);

    expect(DB::table('shift_entries')->where('work_date', SCHEDULED_OFFBOARDING_LAST_DAY)->whereNotNull('clocked_out_at')->count())->toBe(1);
})->group('RN-14', 'RN-05', 'RF-AT-03');

it('admite la vuelta de la pausa de madrugada del ultimo dia en su jornada', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee']);
    FrozenTime::at('2026-10-31 06:00:00');
    $scanId = Str::uuid7()->toString();

    ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-30T21:00:00Z')->assertOk();
    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => '2026-10-31T01:00:00Z', 'qr_payload' => $escenario['card'], 'intent' => 'break_start'])
        ->assertOk();

    ficharConLaBajaProgramada($escenario['token'], $escenario['card'], '2026-10-31T01:30:00Z')
        ->assertOk()
        ->assertJsonPath('action', 'break_end')
        ->assertJsonPath('work_date', SCHEDULED_OFFBOARDING_LAST_DAY);
})->group('RN-14', 'RN-05', 'RF-AT-12');

it('rechaza la entrada por PIN del dia siguiente sin atribuirla a nadie', function (): void {
    FrozenTime::at('2026-10-02 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    EmployeePins::issue($escenario['employee'], '482913');
    $publicKey = EmployeePins::configureSealing();
    programarLaBaja($escenario['employee']);
    FrozenTime::at('2026-10-31 08:00:00');
    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-10-31T07:30:00Z',
            'employee_code' => EmployeePins::codeOf($escenario['employee']),
            'pin_sealed' => EmployeePins::seal('482913', $publicKey),
        ])
        ->assertStatus(422)
        ->assertValidResponse(422);

    $fila = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($fila?->result)->toBe('rejected_unknown')
        ->and($fila?->employee_id)->toBeNull()
        ->and(DB::table('shift_entries')->count())->toBe(0);
})->group('RN-14', 'RS-03', 'RF-AT-11');

it('rechaza a mano un tramo de una jornada posterior al cese, y admite el del propio dia', function (): void {
    FrozenTime::at('2026-09-20 10:00:00');
    $escenario = escenarioDeLaBajaProgramada();
    programarLaBaja($escenario['employee'], '2026-10-01');
    // Cese el 1 y hoy el 2: la baja ya rige, aunque la tarea no la haya aplicado.
    FrozenTime::at('2026-10-02 10:00:00');
    $tramo = static fn (string $dia): array => [
        'employee_uuid' => $escenario['employee'],
        'work_date' => $dia,
        'clocked_in_at' => $dia.'T06:00:00Z',
        'clocked_out_at' => $dia.'T09:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
    ];

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/shift-entries', $tramo('2026-10-02'))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.work_date.0', 'Esta persona tiene la baja programada con cese el 2026-10-01: no se le '
            .'pueden registrar horas de jornadas posteriores a esa fecha.');

    Api::as(tokenDeLaBajaProgramada())
        ->post('/api/v1/shift-entries', $tramo('2026-10-01'))
        ->assertValidResponse(201);

    expect(DB::table('shift_entries')->where('work_date', '>', '2026-10-01')->count())->toBe(0);
})->group('RN-14', 'RF-PA-04');
