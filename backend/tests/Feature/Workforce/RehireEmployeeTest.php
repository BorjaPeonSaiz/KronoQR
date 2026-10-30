<?php

declare(strict_types=1);

use App\Exceptions\ProblemDetails;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMaterial;
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
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA READMISION DESDE FUERA** (RN-27, RF-GP-01, RF-GP-03, ADR-062 §6 y §10).
 *
 * Una persona de baja vuelve a la plantilla en su misma ficha, con un periodo de
 * alta nuevo, una tarjeta nueva pendiente de imprimir y su PIN de siempre; el
 * hueco entre los dos periodos no admite tramos. En lote, todo o nada.
 *
 * El centro esta en Madrid. «Lucia» se dio de alta el 1 de enero de 2026 y cesa
 * el 31 de octubre; vuelve el 1 de abril de 2027.
 */

uses(RefreshDatabase::class);

const REHIRE_TEST_TERMINATION = '2026-10-31';

const REHIRE_TEST_RETURN = '2027-04-01';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function tokenDeLaReadmision(UserRole $role = UserRole::RRHH): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole($role));
}

/**
 * Una persona con tarjeta, dada de baja con cese el 31 de octubre (en el acto:
 * la fecha ya paso), y el reloj el dia de su vuelta.
 *
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string}
 */
function personaDeBajaParaReadmitir(): array
{
    FrozenTime::at('2026-11-05 10:00:00');
    $escenario = AttendanceFixtures::scenario();
    Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']));

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/offboard', ['terminated_at' => REHIRE_TEST_TERMINATION])
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated');

    FrozenTime::at(REHIRE_TEST_RETURN.' 08:00:00');

    return $escenario;
}

function textoDeLaReadmision(mixed $valor): string
{
    return is_string($valor) ? $valor : throw new RuntimeException('Se esperaba un texto en la respuesta.');
}

/**
 * @return list<array<string, mixed>>
 */
function asientosDeLaReadmision(string $action): array
{
    return array_values(DB::table('audit_log')->where('action', $action)->orderBy('id')->get()
        ->map(static function (object $row): array {
            /** @var object{payload: string} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return $payload;
        })->values()->all());
}

it('readmite en la misma ficha con un periodo nuevo, una tarjeta pendiente de imprimir y su asiento', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $codigo = DB::table('employees')->where('uuid', $escenario['employee'])->value('employee_code');

    $respuesta = Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('employee.uuid', $escenario['employee'])
        ->assertJsonPath('employee.employee_code', $codigo)
        ->assertJsonPath('employee.status', 'active')
        ->assertJsonPath('employee.hired_at', REHIRE_TEST_RETURN)
        ->assertJsonPath('employee.terminated_at', null)
        ->assertJsonPath('employment_period.state', 'active')
        ->assertJsonPath('credential.status', 'pending_print')
        ->assertJsonMissingPath('pin');

    $credencial = textoDeLaReadmision($respuesta->json('credential.uuid'));
    $periodo = textoDeLaReadmision($respuesta->json('employment_period.uuid'));
    $empleado = AttendanceFixtures::employeeIdOf($escenario['employee']);

    // La tarjeta nueva, pendiente de imprimir; las de antes, revocadas.
    expect(DB::table('credentials')->where('uuid', $credencial)->whereNull('revoked_at')->whereNull('printed_at')->count())->toBe(1)
        ->and(DB::table('credentials')->where('employee_id', $empleado)->where('uuid', '<>', $credencial)->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('employment_periods')->where('employee_id', $empleado)->orderBy('hired_on')->pluck('hired_on')->all())
        ->toBe(['2026-01-01', REHIRE_TEST_RETURN]);

    expect(asientosDeLaReadmision('employee.rehired'))->toEqual([[
        'employee_uuid' => $escenario['employee'],
        'employment_period_uuid' => $periodo,
        'hired_at' => REHIRE_TEST_RETURN,
        'department_id' => $escenario['department'],
        'pin_reset' => false,
        'via_import' => false,
    ]])
        ->and(DB::table('audit_log')->where('action', 'credential.issued')->where('payload->credential_uuid', $credencial)->count())->toBe(1);

    // La ficha lista sus dos periodos.
    Api::as(tokenDeLaReadmision())
        ->get('/api/v1/employees/'.$escenario['employee'])
        ->assertValidResponse(200)
        ->assertJsonPath('employment_periods.0.hired_at', '2026-01-01')
        ->assertJsonPath('employment_periods.0.terminated_at', REHIRE_TEST_TERMINATION)
        ->assertJsonPath('employment_periods.0.state', 'terminated')
        ->assertJsonPath('employment_periods.1.hired_at', REHIRE_TEST_RETURN)
        ->assertJsonPath('employment_periods.1.state', 'active');
})->group('RN-27', 'RF-GP-01', 'RF-QR-03', 'RL-04');

it('no admite tramos en el hueco entre los dos periodos y si en el periodo anterior', function (): void {
    $escenario = personaDeBajaParaReadmitir();

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(200);

    $token = tokenDeLaReadmision();

    Api::as($token)
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $escenario['employee'],
            'work_date' => '2027-01-15',
            'clocked_in_at' => '2027-01-15T07:00:00Z',
            'clocked_out_at' => '2027-01-15T15:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor('work_date');

    Api::as($token)
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $escenario['employee'],
            'work_date' => '2026-10-20',
            'clocked_in_at' => '2026-10-20T06:00:00Z',
            'clocked_out_at' => '2026-10-20T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertValidResponse(201);
})->group('RN-27', 'RF-PA-04');

it('rechaza una fecha de alta que no es posterior al cese y no escribe nada', function (): void {
    $escenario = personaDeBajaParaReadmitir();

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_TERMINATION])
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor('hired_at');

    expect(DB::table('employees')->where('uuid', $escenario['employee'])->value('status'))->toBe('terminated')
        ->and(asientosDeLaReadmision('employee.rehired'))->toBe([]);
})->group('RN-27');

it('responde 409 employee-not-terminated a quien no esta de baja, tambien a la segunda readmision', function (): void {
    $escenario = personaDeBajaParaReadmitir();

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(200);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => '2027-05-01'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', ProblemDetails::TYPE_EMPLOYEE_NOT_TERMINATED);

    expect(asientosDeLaReadmision('employee.rehired'))->toHaveCount(1);
})->group('RN-27');

it('con reset_pin devuelve un PIN nuevo una sola vez; sin el conserva el que tenia', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $antes = DB::table('employees')->where('uuid', $escenario['employee'])->value('pin_hash');

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN, 'reset_pin' => true])
        ->assertValidResponse(200)
        ->assertJsonPath('pin.employee_uuid', $escenario['employee'])
        ->assertJsonPath('employee.pin_status', 'issued');

    expect(DB::table('employees')->where('uuid', $escenario['employee'])->value('pin_hash'))->not->toBe($antes)
        ->and(asientosDeLaReadmision('employee.rehired')[0]['pin_reset'] ?? null)->toBeTrue();
})->group('RN-27', 'RF-ID-09');

it('cierra el contrato abierto en el cese y abre otro con sus terminos', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $empleado = AttendanceFixtures::employeeIdOf($escenario['employee']);

    DB::table('employment_contracts')->insert([
        'employee_id' => $empleado,
        'weekly_hours' => 30,
        'annual_hours' => null,
        'schedule_type' => 'turnos',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'created_at' => now(),
    ]);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(200);

    /** @var list<object{valid_from: string, valid_to: string|null, weekly_hours: string, schedule_type: string}> $contratos */
    $contratos = DB::table('employment_contracts')->where('employee_id', $empleado)->orderBy('valid_from')
        ->get(['valid_from', 'valid_to', 'weekly_hours', 'schedule_type'])->all();

    expect($contratos)->toHaveCount(2)
        ->and($contratos[0]->valid_to)->toBe(REHIRE_TEST_TERMINATION)
        ->and($contratos[1]->valid_from)->toBe(REHIRE_TEST_RETURN)
        ->and($contratos[1]->valid_to)->toBeNull()
        ->and((float) $contratos[1]->weekly_hours)->toBe(30.0)
        ->and($contratos[1]->schedule_type)->toBe('turnos');
})->group('RN-27', 'RF-GP-02', 'RF-IN-03');

it('con contract null cierra el contrato abierto en el cese sin abrir otro, y el cierre queda en el asiento', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $empleado = AttendanceFixtures::employeeIdOf($escenario['employee']);

    DB::table('employment_contracts')->insert([
        'employee_id' => $empleado,
        'weekly_hours' => 30,
        'annual_hours' => 1500,
        'schedule_type' => 'turnos',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'created_at' => now(),
    ]);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN, 'contract' => null])
        ->assertValidRequest()
        ->assertValidResponse(200);

    /** @var list<object{valid_from: string, valid_to: string|null}> $contratos */
    $contratos = DB::table('employment_contracts')->where('employee_id', $empleado)->orderBy('valid_from')
        ->get(['valid_from', 'valid_to'])->all();

    expect($contratos)->toHaveCount(1)
        ->and($contratos[0]->valid_to)->toBe(REHIRE_TEST_TERMINATION)
        ->and(DB::table('audit_log')->where('action', 'employment_contract.registered')->count())->toBe(0)
        ->and(asientosDeLaReadmision('employee.rehired'))->toHaveCount(1)
        ->and(asientosDeLaReadmision('employee.rehired')[0])->toMatchArray([
            'previous_contract_valid_from' => '2026-01-01',
            'previous_contract_valid_to' => REHIRE_TEST_TERMINATION,
            'previous_contract_weekly_hours' => 30.0,
            'previous_contract_annual_hours' => 1500.0,
            'previous_contract_schedule_type' => 'turnos',
        ]);
})->group('RN-27', 'RF-GP-02', 'RF-IN-03');

it('sin contrato abierto el asiento no lleva previous_contract', function (): void {
    $escenario = personaDeBajaParaReadmitir();

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN, 'contract' => null])
        ->assertValidResponse(200);

    expect(asientosDeLaReadmision('employee.rehired')[0])->not->toHaveKey('previous_contract_valid_to');
})->group('RN-27', 'RF-GP-02');

it('cambia de departamento si se pide y deja el anterior en el asiento', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $otro = Department::query()->create(['site_id' => $escenario['site'], 'name' => 'Pisos']);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', [
            'hired_at' => REHIRE_TEST_RETURN,
            'department_id' => $otro->id,
            'contract' => null,
        ])
        ->assertValidResponse(200)
        ->assertJsonPath('employee.department_id', $otro->id);

    expect(asientosDeLaReadmision('employee.rehired')[0])
        ->toMatchArray(['department_id' => $otro->id, 'previous_department_id' => $escenario['department']]);
})->group('RN-27', 'RF-GP-01');

it('el alta con el documento de una persona de baja responde 409 con la persona que se puede readmitir', function (): void {
    FrozenTime::at('2026-11-05 10:00:00');
    AttendanceFixtures::scenario();
    $token = tokenDeLaReadmision();

    $alta = Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'national_id' => '00000000T',
        'hired_at' => '2026-01-01',
    ])->assertValidResponse(201);

    $uuid = textoDeLaReadmision($alta->json('employee.uuid'));

    Api::as($token)->post('/api/v1/employees/'.$uuid.'/offboard', ['terminated_at' => REHIRE_TEST_TERMINATION])
        ->assertValidResponse(200);

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'national_id' => '00000000-T',
        'hired_at' => REHIRE_TEST_RETURN,
    ])
        ->assertValidResponse(409)
        ->assertJsonPath('rehirable_employee_uuid', $uuid);
})->group('RN-27', 'RF-GP-01');

// --- En lote -------------------------------------------------------------------

it('readmite a varias personas a la vez, cada una con su asiento y su tarjeta', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $otra = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated');

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/rehire', [
            'employee_uuids' => [$escenario['employee'], $otra],
            'hired_at' => REHIRE_TEST_RETURN,
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'rehired')
        ->assertJsonPath('rehired.0.employee.uuid', $escenario['employee'])
        ->assertJsonPath('rehired.1.employee.uuid', $otra)
        ->assertJsonPath('rehired.1.credential.status', 'pending_print');

    expect(asientosDeLaReadmision('employee.rehired'))->toHaveCount(2)
        ->and(DB::table('employees')->whereIn('uuid', [$escenario['employee'], $otra])->where('status', 'active')->count())->toBe(2);
})->group('RN-27');

it('si una no se puede readmitir no readmite a ninguna y dice cual y por que', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $enAlta = WorkforceFixtures::employee($escenario['site'], $escenario['department']);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/rehire', [
            'employee_uuids' => [$escenario['employee'], $enAlta, '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'],
            'hired_at' => REHIRE_TEST_RETURN,
        ])
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor('employee_uuids.1')
        ->assertJsonValidationErrorFor('employee_uuids.2')
        ->assertJsonMissingValidationErrors('employee_uuids.0');

    expect(DB::table('employees')->where('uuid', $escenario['employee'])->value('status'))->toBe('terminated')
        ->and(asientosDeLaReadmision('employee.rehired'))->toBe([])
        ->and(DB::table('employment_periods')->count())->toBe(DB::table('employees')->count());
})->group('RN-27');

it('con reset_pin rechaza un lote que no se puede readmitir sin calcular ningun PIN', function (): void {
    // Cada PIN es un bcrypt: un lote imposible no los paga (revision de la 25.3).
    $escenario = personaDeBajaParaReadmitir();
    $enAlta = WorkforceFixtures::employee($escenario['site'], $escenario['department']);
    $hashes = new class implements PinHasher
    {
        public int $calls = 0;

        public function hash(#[SensitiveParameter] string $pin): PinMaterial
        {
            $this->calls++;

            throw new RuntimeException('No se tenia que calcular ningun PIN.');
        }
    };
    app()->instance(PinHasher::class, $hashes);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/rehire', [
            'employee_uuids' => [$escenario['employee'], $enAlta],
            'hired_at' => REHIRE_TEST_RETURN,
            'reset_pin' => true,
        ])
        ->assertValidResponse(422)
        ->assertJsonValidationErrorFor('employee_uuids.1');

    expect($hashes->calls)->toBe(0)
        ->and(DB::table('employees')->where('uuid', $escenario['employee'])->value('status'))->toBe('terminated');
})->group('RN-27', 'RF-ID-09');

it('responde 404 a la readmision de una persona que no existe', function (): void {
    FrozenTime::at(REHIRE_TEST_RETURN.' 08:00:00');
    AttendanceFixtures::scenario();

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(404);
})->group('RN-27');

it('en lote, un contrato que pisaria el periodo nuevo responde 409 y no readmite a nadie', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    $otra = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated');

    // Un contrato abierto que empieza despues de la fecha de alta comun.
    DB::table('employment_contracts')->insert([
        'employee_id' => AttendanceFixtures::employeeIdOf($otra),
        'weekly_hours' => 20,
        'schedule_type' => 'continua',
        'valid_from' => '2027-05-01',
        'valid_to' => null,
        'created_at' => now(),
    ]);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/rehire', [
            'employee_uuids' => [$escenario['employee'], $otra],
            'hired_at' => REHIRE_TEST_RETURN,
        ])
        ->assertValidResponse(409);

    expect(asientosDeLaReadmision('employee.rehired'))->toBe([])
        ->and(DB::table('employees')->whereIn('uuid', [$escenario['employee'], $otra])->where('status', 'terminated')->count())->toBe(2);
})->group('RN-27', 'RF-GP-02');

it('vuelve a entrar al portal con el PIN de siempre y ve el registro de los dos periodos', function (): void {
    $escenario = personaDeBajaParaReadmitir();
    EmployeePins::issue($escenario['employee'], PortalLogins::PIN);
    $codigo = EmployeePins::codeOf($escenario['employee']);

    // De baja, el portal no la admite.
    Api::guest()->post('/api/v1/me/login', ['employee_code' => $codigo, 'pin' => PortalLogins::PIN])->assertStatus(401);

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(200);

    $sesion = PortalLogins::tokenFor($codigo);

    Api::as($sesion)->get('/api/v1/me/workdays', ['from' => '2026-10-01', 'to' => '2027-04-01'])->assertStatus(200);
})->group('RN-27', 'RL-05', 'RF-ID-06');

it('tras la readmision se registra y se corrige una ausencia del periodo anterior, y ninguna en el hueco', function (): void {
    // RF-GP-04, RN-27: la ausencia cae entera en ALGUN periodo de alta, no solo
    // en el periodo en curso.
    $escenario = personaDeBajaParaReadmitir();
    $token = tokenDeLaReadmision();

    Api::as($token)
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(200);

    $ausencia = textoDeLaReadmision(Api::as($token)
        ->post('/api/v1/absences', [
            'employee_uuid' => $escenario['employee'],
            'type' => 'vacation',
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-06',
        ])
        ->assertValidResponse(201)
        ->json('uuid'));

    Api::as($token)
        ->patch('/api/v1/absences/'.$ausencia, ['ends_on' => '2026-03-10', 'reason' => 'Se alargaron una semana.'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('ends_on', '2026-03-10');

    $vigente = textoDeLaReadmision(DB::table('absences')->where('status', 'active')->value('uuid'));

    // Llevarla al hueco, o registrarla en el, no.
    Api::as($token)
        ->patch('/api/v1/absences/'.$vigente, ['starts_on' => '2026-10-30', 'ends_on' => '2026-11-02', 'reason' => 'Fechas erroneas.'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');

    Api::as($token)
        ->post('/api/v1/absences', [
            'employee_uuid' => $escenario['employee'],
            'type' => 'leave',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-03',
        ])
        ->assertStatus(422);

    expect(DB::table('absences')->where('status', 'active')->pluck('ends_on')->all())->toBe(['2026-03-10']);
})->group('RF-GP-04', 'RN-27');

/**
 * @return TestResponse<Response>
 */
function ficharTrasLaReadmision(string $deviceToken, string $card, string $occurredAt): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($deviceToken)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => $occurredAt, 'qr_payload' => $card]);
}

it('rechaza con el rechazo generico un fichaje de la cola cuya jornada cae en el hueco, y admite el del dia de la vuelta', function (): void {
    // ADR-062 §6 (decision del 30-10-2026): una jornada anterior al alta del
    // periodo en curso se trata como la posterior al cese. El hueco no se
    // puede corregir desde el panel, asi que tampoco entra por el quiosco.
    $escenario = personaDeBajaParaReadmitir();

    Api::as(tokenDeLaReadmision())
        ->post('/api/v1/employees/'.$escenario['employee'].'/rehire', ['hired_at' => REHIRE_TEST_RETURN])
        ->assertValidResponse(200);

    $tarjeta = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']))->toString();

    // Desde la cola: 31 de marzo, la vispera de la vuelta.
    $enElHueco = ficharTrasLaReadmision($escenario['token'], $tarjeta, '2027-03-31T10:00:00Z');
    $desconocida = ficharTrasLaReadmision($escenario['token'], Credentials::signedWithUnknownKey()->toString(), '2027-03-31T10:01:00Z');

    $enElHueco->assertStatus(422)->assertValidResponse(422);
    $cuerpo = (array) $enElHueco->json();
    $otro = (array) $desconocida->json();
    unset($cuerpo['scan_id'], $otro['scan_id']);

    $fila = DB::table('scan_events')->where('scan_id', $enElHueco->json('scan_id'))->first();

    expect($cuerpo)->toBe($otro)
        ->and($fila?->result)->toBe('rejected_unknown')
        ->and($fila?->employee_id)->toBe(AttendanceFixtures::employeeIdOf($escenario['employee']))
        ->and(DB::table('shift_entries')->where('work_date', '<', REHIRE_TEST_RETURN)->where('work_date', '>', REHIRE_TEST_TERMINATION)->count())->toBe(0);

    // El dia de la vuelta, si.
    ficharTrasLaReadmision($escenario['token'], $tarjeta, REHIRE_TEST_RETURN.'T06:30:00Z')->assertOk();

    expect(DB::table('shift_entries')->where('work_date', REHIRE_TEST_RETURN)->count())->toBe(1);
})->group('RN-27', 'RS-03', 'RF-AT-02');

it('rechaza el fichaje de quien aun no ha llegado a su fecha de alta, readmitida o no', function (): void {
    // Alta el 1 de enero de 2026; el 31 de diciembre todavia no.
    FrozenTime::at('2025-12-31 10:00:00');
    $escenario = AttendanceFixtures::scenario();
    $tarjeta = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']))->toString();

    ficharTrasLaReadmision($escenario['token'], $tarjeta, '2025-12-31T09:00:00Z')
        ->assertStatus(422)
        ->assertValidResponse(422);

    expect(DB::table('shift_entries')->count())->toBe(0)
        ->and(DB::table('scan_events')->value('result'))->toBe('rejected_unknown');
})->group('RN-27', 'RS-03', 'RF-AT-02');

it('el periodo cerrado lleva como baja efectiva el instante del reloj de la aplicacion', function (): void {
    // Regla dura 2: el mismo instante que el asiento employee.offboarded, no el
    // now() de la base.
    $escenario = personaDeBajaParaReadmitir();

    expect(DB::table('employment_periods')->where('employee_id', AttendanceFixtures::employeeIdOf($escenario['employee']))->value('offboarded_at'))
        ->toStartWith('2026-11-05 10:00:00');
})->group('RN-27', 'RN-14');
