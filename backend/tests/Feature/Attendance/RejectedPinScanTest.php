<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Attendance\Domain\Event\ScanRejected;
use App\Modules\Compliance\Application\Port\IncidentResolutionMetrics;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Compliance\RecordingIncidentResolutionMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **RN-19 de punta a punta**: un fichaje por PIN que no queda registrado no se
 * pierde en silencio (ADR-043, hallazgos PIN-06 y SC7-02).
 *
 * Dos mitades, y las dos importan igual:
 *
 *   - **La respuesta de `/scan/pin` no cambia.** Codigo existente con PIN
 *     erroneo y codigo inexistente siguen siendo indistinguibles —codigo,
 *     cuerpo, cabeceras— y validados contra el contrato (RS-03, regla dura 17).
 *     Lo unico distinto es la fila de `scan_events`, que el cliente no ve.
 *   - **La revision diaria lo convierte en incidencia** si nadie lo subsana en
 *     10 minutos: una por persona y jornada, sin spam, y una descartada no se
 *     reabre.
 */

uses(RefreshDatabase::class);

const REJECTED_PIN_GOOD = '481902';

const REJECTED_PIN_BAD = '481903';

/**
 * Empleado activo con PIN en un departamento con responsable, y quiosco.
 *
 * @return array{site: int, department: int, employee: string, code: string, publicKey: string, device: int, token: string, manager: int, managerToken: string}
 */
function rejectedPinScenario(string $now = '2026-03-14 09:05:00'): array
{
    $scenario = AttendanceFixtures::scenario();
    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Department::query()->whereKey($scenario['department'])->update(['manager_user_id' => $manager->id]);

    EmployeePins::issue($scenario['employee'], REJECTED_PIN_GOOD);

    FrozenTime::at($now);

    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app(Cache::class)->clear();
    app()->forgetInstance(PinAttempts::class);

    Spectator::using('openapi.yaml');

    return [
        ...$scenario,
        'code' => EmployeePins::codeOf($scenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
        'manager' => $manager->id,
        'managerToken' => ManagementUsers::tokenFor($manager),
    ];
}

/**
 * @param  array{token: string, code: string, publicKey: string, ...}  $scenario
 * @return TestResponse<Response>
 */
function rejectedPinPost(array $scenario, string $scanId, string $pin, string $occurredAt, ?string $code = null): TestResponse
{
    return Api::as($scenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'employee_code' => $code ?? $scenario['code'],
            'pin_sealed' => EmployeePins::seal($pin, $scenario['publicKey']),
        ]);
}

function rejectedPinDetect(string $now = '2026-03-15 03:30:00'): void
{
    Notification::fake();
    FrozenTime::at($now);

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);
}

/**
 * Cabeceras de una respuesta sin las que cambian en cada peticion por diseño.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string|null>>
 */
function rejectedPinStableHeaders(TestResponse $response): array
{
    $headers = $response->headers->all();

    // El contador del limite de peticiones baja en uno por peticion: es del
    // dispositivo, no del codigo tecleado, y es igual para los dos caminos.
    unset($headers['date'], $headers['x-request-id'], $headers['traceparent'], $headers['x-trace-id'], $headers['x-ratelimit-remaining']);
    ksort($headers);

    return $headers;
}

// --- F1 / F6 · la respuesta no cambia y el log no delata ---------------------

it('responde igual a un PIN erroneo que a un codigo inexistente y solo la fila anota al dueño', function (): void {
    $scenario = rejectedPinScenario();

    $withOwner = Str::uuid7()->toString();
    $withoutOwner = Str::uuid7()->toString();

    $first = rejectedPinPost($scenario, $withOwner, REJECTED_PIN_BAD, '2026-03-14T09:04:00Z');
    $second = rejectedPinPost($scenario, $withoutOwner, REJECTED_PIN_BAD, '2026-03-14T09:04:00Z', code: 'ENOEXISTE');

    $first->assertStatus(422)->assertValidRequest()->assertValidResponse();
    $second->assertStatus(422)->assertValidRequest()->assertValidResponse();

    $bodyOf = static fn (TestResponse $response): array => array_diff_key((array) $response->json(), ['scan_id' => true]);

    expect($bodyOf($first))->toBe($bodyOf($second))
        ->and(rejectedPinStableHeaders($first))->toBe(rejectedPinStableHeaders($second))
        ->and((string) $first->getContent())->not->toContain($scenario['employee']);

    /** @var array<string, object{claimed_employee_id: int|null, pin_lockout: bool|null, employee_id: int|null, result: string}> $rows */
    $rows = DB::table('scan_events')->whereIn('scan_id', [$withOwner, $withoutOwner])->get()->keyBy('scan_id')->all();

    expect($rows[$withOwner]->claimed_employee_id)->toBe(AttendanceFixtures::employeeIdOf($scenario['employee']))
        ->and($rows[$withOwner]->pin_lockout)->toBeFalse()
        ->and($rows[$withOwner]->employee_id)->toBeNull()
        ->and($rows[$withOwner]->result)->toBe('rejected_unknown')
        ->and($rows[$withoutOwner]->claimed_employee_id)->toBeNull()
        ->and($rows[$withoutOwner]->pin_lockout)->toBeNull()
        ->and($rows[$withoutOwner]->result)->toBe('rejected_unknown');
})->group('RN-19', 'RS-03', 'RF-AT-11');

it('no deja el dueño ni el codigo en el log ni en el evento ScanRejected', function (): void {
    $scenario = rejectedPinScenario();

    /** @var list<array{message: string, context: array<string, mixed>}> $logged */
    $logged = [];
    Log::listen(static function (MessageLogged $message) use (&$logged): void {
        $logged[] = ['message' => $message->message, 'context' => $message->context];
    });

    /** @var list<ScanRejected> $rejections */
    $rejections = [];
    Event::listen(ScanRejected::class, static function (ScanRejected $event) use (&$rejections): void {
        $rejections[] = $event;
    });

    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_BAD, '2026-03-14T09:04:00Z')->assertStatus(422);

    $serialized = json_encode($logged, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain($scenario['employee'])
        ->and($serialized)->not->toContain($scenario['code'])
        ->and($serialized)->toContain('auth.login_failed')
        ->and($rejections)->toHaveCount(1)
        ->and($rejections[0]->employeeUuid)->toBeNull();
})->group('RN-19', 'RS-03', 'RQ-07');

// --- F2 · el reenvio -----------------------------------------------------------

it('devuelve lo mismo al reenviar el scan_id y no suma fallos ni filas', function (): void {
    $scenario = rejectedPinScenario();
    $scanId = Str::uuid7()->toString();

    $original = rejectedPinPost($scenario, $scanId, REJECTED_PIN_BAD, '2026-03-14T09:04:00Z');
    $replays = [
        rejectedPinPost($scenario, $scanId, REJECTED_PIN_BAD, '2026-03-14T09:04:00Z'),
        rejectedPinPost($scenario, $scanId, REJECTED_PIN_BAD, '2026-03-14T09:04:00Z'),
    ];

    foreach ($replays as $replay) {
        $replay->assertStatus(422)->assertValidResponse();
        expect($replay->json())->toBe($original->json());
    }

    expect(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1)
        ->and(DB::table('scan_events')->whereNotNull('claimed_employee_id')->count())->toBe(1);

    // Si los dos reenvios hubieran contado, serian tres fallos y el PIN bueno
    // chocaria con el bloqueo (PIN-05).
    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_GOOD, '2026-03-14T09:04:30Z')->assertOk();
})->group('RN-19', 'RF-AT-07', 'RS-12');

// --- F3 · el escenario de SC7-02 ---------------------------------------------

it('abre una incidencia por la cola de PIN que se rechaza al sincronizar', function (): void {
    // Cuatro fichajes por PIN encolados sin red hace dos horas: tres erroneos y
    // el correcto. Al sincronizar, el tercero abre el bloqueo y el cuarto —el
    // bueno— choca con el. Sin RN-19 la jornada se perdia en silencio.
    $scenario = rejectedPinScenario('2026-03-14 09:05:00');

    foreach ([
        ['07:02:00', REJECTED_PIN_BAD],
        ['07:02:20', REJECTED_PIN_BAD],
        ['07:02:40', REJECTED_PIN_BAD],
        ['07:03:00', REJECTED_PIN_GOOD],
    ] as [$at, $pin]) {
        rejectedPinPost($scenario, Str::uuid7()->toString(), $pin, '2026-03-14T'.$at.'Z')->assertStatus(422);
    }

    expect(DB::table('shift_entries')->count())->toBe(0);

    rejectedPinDetect();

    $incidents = DB::table('incidents')->where('type', 'rejected_pin_scan')->get();

    expect($incidents)->toHaveCount(1);

    $incident = $incidents->first();

    /** @var array<string, int|string> $context */
    $context = json_decode((string) $incident?->context, true, 512, JSON_THROW_ON_ERROR);

    expect($incident?->work_date)->toBe('2026-03-14')
        ->and($incident?->severity)->toBe('medium')
        ->and($incident?->shift_entry_id)->toBeNull()
        ->and($incident?->assigned_to_user_id)->toBe($scenario['manager'])
        ->and($context['attempts'])->toBe(4)
        ->and($context['lockout_attempts'])->toBe(2)
        ->and($context['max_sync_delay_seconds'])->toBeGreaterThanOrEqual(7200)
        ->and($context['occurred_at'])->toBe('2026-03-14T07:02:00.000000Z');

    config()->set('identity.two_factor.required_roles', []);
    app()->instance(IncidentResolutionMetrics::class, new RecordingIncidentResolutionMetrics);

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))->get('/api/v1/incidents');

    $response->assertValidResponse(200);

    expect(collect((array) $response->json('data'))->pluck('type')->all())->toContain('rejected_pin_scan');
})->group('RN-19', 'RF-PR-01', 'RS-12', 'RF-AT-11');

// --- F4 / F5 · lo que NO abre incidencia -------------------------------------

it('no abre nada si el PIN correcto llega treinta segundos despues', function (): void {
    $scenario = rejectedPinScenario('2026-03-14 07:02:00');

    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_BAD, '2026-03-14T07:02:00Z')->assertStatus(422);

    FrozenTime::at('2026-03-14 07:02:30');
    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_GOOD, '2026-03-14T07:02:30Z')->assertOk();

    rejectedPinDetect();

    expect(DB::table('incidents')->where('type', 'rejected_pin_scan')->count())->toBe(0);
})->group('RN-19', 'RF-PR-01');

it('no anota ni abre nada con el codigo de alguien de baja', function (): void {
    $scenario = rejectedPinScenario();

    WorkforceFixtures::terminate($scenario['employee']);

    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_BAD, '2026-03-14T09:04:00Z')
        ->assertStatus(422)
        ->assertValidResponse();

    expect(DB::table('scan_events')->whereNotNull('claimed_employee_id')->count())->toBe(0);

    rejectedPinDetect();

    expect(DB::table('incidents')->where('type', 'rejected_pin_scan')->count())->toBe(0);
})->group('RN-19', 'RN-14');

// --- I5 · sin spam -------------------------------------------------------------

it('abre una sola por persona y dia aunque la pasada se repita', function (): void {
    $scenario = rejectedPinScenario('2026-03-14 07:02:00');

    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_BAD, '2026-03-14T07:02:00Z')->assertStatus(422);
    FrozenTime::at('2026-03-14 18:00:00');
    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_BAD, '2026-03-14T18:00:00Z')->assertStatus(422);

    rejectedPinDetect('2026-03-15 03:30:00');
    rejectedPinDetect('2026-03-16 03:30:00');

    expect(DB::table('incidents')->where('type', 'rejected_pin_scan')->count())->toBe(1);
})->group('RN-19', 'RF-PR-01');

it('no reabre una incidencia descartada en la pasada siguiente', function (): void {
    $scenario = rejectedPinScenario('2026-03-14 07:02:00');

    rejectedPinPost($scenario, Str::uuid7()->toString(), REJECTED_PIN_BAD, '2026-03-14T07:02:00Z')->assertStatus(422);

    rejectedPinDetect('2026-03-15 03:30:00');

    $resolver = ManagementUsers::withRole(UserRole::RRHH);

    DB::table('incidents')->where('type', 'rejected_pin_scan')->update([
        'status' => 'dismissed',
        'resolved_at' => '2026-03-15 09:00:00+00',
        'resolved_by_user_id' => $resolver->id,
        'resolution_note' => 'No trabajo ese dia: se equivoco de codigo.',
    ]);

    rejectedPinDetect('2026-03-16 03:30:00');

    expect(DB::table('incidents')->where('type', 'rejected_pin_scan')->count())->toBe(1)
        ->and(DB::table('incidents')->where('type', 'rejected_pin_scan')->value('status'))->toBe('dismissed');
})->group('RN-19', 'RF-PR-01', 'RF-PA-05');

// --- Autorizacion negativa -----------------------------------------------------

it('no deja ver ni resolver la incidencia a quien hoy recibe 403', function (string $actor): void {
    $scenario = rejectedPinScenario();

    $incident = IncidentFixtures::open(
        $scenario['employee'],
        type: 'rejected_pin_scan',
        severity: 'medium',
        assignedToUserId: $scenario['manager'],
        context: ['scan_id' => Str::uuid7()->toString(), 'occurred_at' => '2026-03-14T07:02:00.000000Z', 'attempts' => 1, 'lockout_attempts' => 0, 'max_sync_delay_seconds' => 0],
    );

    config()->set('identity.two_factor.required_roles', []);

    $token = match ($actor) {
        'quiosco' => $scenario['token'],
        'auditor' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)),
        'empleado' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO)),
        default => throw new LogicException($actor),
    };

    Api::as($token)->get('/api/v1/incidents')->assertForbidden();
    Api::as($token)->post('/api/v1/incidents/'.$incident.'/resolve', [
        'outcome' => 'dismissed',
        'note' => 'No deberia poder cerrar esto.',
    ])->assertForbidden();

    expect(DB::table('incidents')->where('id', $incident)->value('status'))->toBe('open');
})->with(['quiosco', 'auditor', 'empleado'])->group('RN-19', 'RF-PA-05', 'RS-05');

it('no la ensena ni deja resolverla al responsable de otro departamento', function (): void {
    $scenario = rejectedPinScenario();

    $incident = IncidentFixtures::open(
        $scenario['employee'],
        type: 'rejected_pin_scan',
        severity: 'medium',
        assignedToUserId: $scenario['manager'],
        context: ['scan_id' => Str::uuid7()->toString(), 'occurred_at' => '2026-03-14T07:02:00.000000Z', 'attempts' => 1, 'lockout_attempts' => 0, 'max_sync_delay_seconds' => 0],
    );

    config()->set('identity.two_factor.required_roles', []);
    app()->instance(IncidentResolutionMetrics::class, new RecordingIncidentResolutionMetrics);

    $otherManager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $other = WorkforceFixtures::department($scenario['site'], 'Cocina');
    Department::query()->whereKey($other)->update(['manager_user_id' => $otherManager->id]);

    $token = ManagementUsers::tokenFor($otherManager);

    $list = Api::as($token)->get('/api/v1/incidents');
    $list->assertValidResponse(200);

    expect($list->json('data'))->toBe([])
        ->and((string) json_encode($list->json()))->not->toContain($scenario['employee']);

    Api::as($token)->post('/api/v1/incidents/'.$incident.'/resolve', [
        'outcome' => 'dismissed',
        'note' => 'No deberia poder cerrar esto.',
    ])->assertForbidden();

    // Y el suyo si la ve: el control positivo que hace significativo lo anterior.
    $own = Api::as($scenario['managerToken'])->get('/api/v1/incidents');
    $own->assertValidResponse(200);

    expect($own->json('data.0.type'))->toBe('rejected_pin_scan');
})->group('RN-19', 'RF-ID-03', 'RF-PA-05', 'RS-05');
