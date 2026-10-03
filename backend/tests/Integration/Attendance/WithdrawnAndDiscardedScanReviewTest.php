<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\FlaggedScans;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La revision diaria de RN-20 y RN-22** (ADR-047) contra PostgreSQL real: lo
 * que el fichaje y el aviso dejaron escrito se convierte en incidencias
 * `scan_before_revocation` y `discarded_scan`, una por persona y jornada, sin
 * tramo y con severidad media, leyendo por `recorded_at`.
 *
 * Los datos entran por la puerta de verdad —`POST /api/v1/scan` con el
 * resolutor HMAC real y `POST /api/v1/scan/discarded`— y la revision es el
 * comando del planificador, `attendance:detect-incidents`.
 *
 * La tarjeta de `Credentials::issueFor()` se emite el 2026-08-14 a las 06:00
 * UTC y, si se revoca, se revoca el 2026-08-15 a las 06:00 UTC. El alta de la
 * plantilla de prueba es el 2026-01-01.
 */

uses(RefreshDatabase::class);

const SCAN_REVIEW_RECEIVED = '2026-08-15 10:00:00';

const SCAN_REVIEW_PASS = '2026-08-16 03:30:00';

/**
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string}
 */
function escenarioDeRevision(): array
{
    $escenario = AttendanceFixtures::scenario();

    FrozenTime::at(SCAN_REVIEW_RECEIVED);
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    return $escenario;
}

/**
 * @return TestResponse<Response>
 */
function fichajeConTarjeta(string $token, string $payload, string $occurredAt): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($token)
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => $occurredAt, 'qr_payload' => $payload]);
}

/**
 * @param  list<array<string, mixed>>  $reports
 */
function avisoDeDescarte(string $token, array $reports): void
{
    Config::set('kiosk.rate_limits.discarded_per_device', 100);

    Api::as($token)->post('/api/v1/scan/discarded', ['reports' => $reports])->assertOk();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function descartePorCodigo(string $code, array $overrides = []): array
{
    return [
        'scan_id' => Str::uuid7()->toString(),
        'occurred_at' => '2026-08-15T06:59:00Z',
        'kind' => 'pin',
        'http_status' => 400,
        'problem_type' => 'urn:kronoqr:problem:invalid-request',
        'discarded_at' => '2026-08-15T07:10:00Z',
        'employee_code' => $code,
        ...$overrides,
    ];
}

function codigoDeEmpleado(string $employeeUuid): string
{
    $code = DB::table('employees')->where('uuid', $employeeUuid)->value('employee_code');

    return is_string($code) ? $code : '';
}

function revisionDiaria(): void
{
    Notification::fake();
    FrozenTime::at(SCAN_REVIEW_PASS);

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);
}

/**
 * @return list<object{employee_id: int, work_date: string, severity: string, shift_entry_id: int|null, context: string}>
 */
function incidenciasDe(string $type): array
{
    /** @var list<object{employee_id: int, work_date: string, severity: string, shift_entry_id: int|null, context: string}> $filas */
    $filas = DB::table('incidents')->where('type', $type)->orderBy('id')->get()->all();

    return $filas;
}

/**
 * @return array<string, int|string>
 */
function contextoDe(object $incidencia): array
{
    /** @var array<string, int|string> $contexto */
    $contexto = json_decode((string) ($incidencia->context ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    return $contexto;
}

// --- RN-20 -------------------------------------------------------------------

it('abre una sola scan_before_revocation por persona y jornada con los escaneos anteriores a la retirada', function (): void {
    $escenario = escenarioDeRevision();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $retirada = Credentials::issueFor($titularId, revokedReason: 'Perdida en el vestuario')->toString();

    fichajeConTarjeta($escenario['token'], $retirada, '2026-08-15T05:00:00Z')->assertStatus(422);
    fichajeConTarjeta($escenario['token'], $retirada, '2026-08-15T05:30:00Z')->assertStatus(422);
    // Posterior a la retirada: la tarjeta ya no valia, no suma.
    fichajeConTarjeta($escenario['token'], $retirada, '2026-08-15T06:30:00Z')->assertStatus(422);

    revisionDiaria();
    revisionDiaria();

    $incidencias = incidenciasDe('scan_before_revocation');

    expect($incidencias)->toHaveCount(1);

    $contexto = contextoDe($incidencias[0]);

    expect($incidencias[0]->employee_id)->toBe($titularId)
        ->and($incidencias[0]->work_date)->toBe('2026-08-15')
        ->and($incidencias[0]->severity)->toBe('medium')
        ->and($incidencias[0]->shift_entry_id)->toBeNull()
        ->and($contexto['attempts'])->toBe(2)
        ->and($contexto['occurred_at'])->toBe('2026-08-15T05:00:00.000000Z')
        ->and($contexto['withdrawal'])->toBe('credential')
        // 5 h de cola: recibido a las 10:00, ocurrido a las 05:00.
        ->and($contexto['max_sync_delay_seconds'])->toBe(18_000)
        // Nunca el motivo libre de la revocacion (regla dura 21).
        ->and(json_encode($contexto, JSON_THROW_ON_ERROR))->not->toContain('vestuario')
        // JSONB no conserva el orden de las claves: se comparan como conjunto.
        ->and(array_keys($contexto))->toEqualCanonicalizing(['scan_id', 'occurred_at', 'attempts', 'max_sync_delay_seconds', 'withdrawal'])
        // Y no abre un `clock_skew` sobre esas filas, por grande que sea el
        // desfase (regresion de `EloquentFlaggedScans`).
        ->and(incidenciasDe('clock_skew'))->toBe([]);
})->group('RN-20', 'RN-14', 'RF-PR-01');

it('dice offboarding cuando la persona esta de baja con la tarjeta todavia vigente', function (): void {
    $escenario = escenarioDeRevision();
    $baja = WorkforceFixtures::employee($escenario['site'], $escenario['department'], 'terminated');
    $tarjeta = Credentials::issueFor(AttendanceFixtures::employeeIdOf($baja))->toString();

    fichajeConTarjeta($escenario['token'], $tarjeta, '2026-08-15T08:00:00Z')->assertStatus(422);

    revisionDiaria();

    $incidencias = incidenciasDe('scan_before_revocation');

    expect($incidencias)->toHaveCount(1)
        ->and(contextoDe($incidencias[0])['withdrawal'])->toBe('offboarding');
})->group('RN-20', 'RN-14', 'RF-PR-01');

it('no devuelve las filas de RN-20 como escaneos marcados de desfase', function (): void {
    // Regresion de la consulta que hubo que filtrar por `result`: la marca de
    // RN-20 no es la de RN-15, y el puerto del desfase solo trae fichajes.
    $escenario = escenarioDeRevision();
    $retirada = Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']), revokedReason: 'lost')->toString();

    fichajeConTarjeta($escenario['token'], $retirada, '2026-08-15T05:00:00Z')->assertStatus(422);

    expect(DB::table('scan_events')->where('flagged_for_review', true)->count())->toBe(1)
        ->and(app(FlaggedScans::class)->flaggedBetween(
            new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-31 00:00:00', new DateTimeZone('UTC')),
        ))->toBe([]);
})->group('RN-20', 'RN-15');

// --- RN-22 -------------------------------------------------------------------

it('abre una sola discarded_scan por persona y jornada con los avisos atribuidos', function (): void {
    $escenario = escenarioDeRevision();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $tarjeta = Credentials::issueFor($titularId)->toString();
    $primero = descartePorCodigo(codigoDeEmpleado($escenario['employee']), ['occurred_at' => '2026-08-15T06:00:00Z']);

    avisoDeDescarte($escenario['token'], [
        $primero,
        [
            'scan_id' => Str::uuid7()->toString(),
            'occurred_at' => '2026-08-15T14:00:00Z',
            'kind' => 'qr',
            'http_status' => 422,
            'problem_type' => 'urn:kronoqr:problem:algo-que-no-existe',
            'discarded_at' => '2026-08-15T14:01:00Z',
            'qr_payload' => $tarjeta,
        ],
    ]);

    // Recibido a las 10:00 y ocurrido a las 14:00: fuera de la tolerancia de
    // desfase (15 min de serie). Ese no abre; el primero si.
    revisionDiaria();
    revisionDiaria();

    $incidencias = incidenciasDe('discarded_scan');

    expect($incidencias)->toHaveCount(1);

    $contexto = contextoDe($incidencias[0]);

    expect($incidencias[0]->employee_id)->toBe($titularId)
        ->and($incidencias[0]->work_date)->toBe('2026-08-15')
        ->and($incidencias[0]->severity)->toBe('medium')
        ->and($incidencias[0]->shift_entry_id)->toBeNull()
        ->and($contexto)->toEqualCanonicalizing([
            'scan_id' => $primero['scan_id'],
            'occurred_at' => '2026-08-15T06:00:00.000000Z',
            'device_uuid' => $escenario['deviceUuid'],
            'origin' => 'pin_kiosk',
            'http_status' => 400,
            'problem' => 'invalid-request',
            'attribution' => 'employee_code',
            'reports' => 1,
        ]);
})->group('RN-22', 'RF-PR-01');

it('cuenta los avisos de la misma jornada y mapea un problema ajeno a other', function (): void {
    $escenario = escenarioDeRevision();
    $codigo = codigoDeEmpleado($escenario['employee']);

    avisoDeDescarte($escenario['token'], [
        descartePorCodigo($codigo, ['occurred_at' => '2026-08-15T06:00:00Z', 'problem_type' => 'urn:kronoqr:problem:algo-que-no-existe']),
        descartePorCodigo($codigo, ['occurred_at' => '2026-08-15T07:00:00Z']),
    ]);

    revisionDiaria();

    $contexto = contextoDe(incidenciasDe('discarded_scan')[0]);

    expect($contexto['reports'])->toBe(2)
        ->and($contexto['problem'])->toBe('other');
})->group('RN-22', 'RF-PR-01');

it('no abre nada sin dueño, con el fichaje ya registrado ni fuera de la ventana creible', function (): void {
    // F6 del dictamen del bloque 18: el aviso se guarda igual, pero la
    // incidencia solo con un `occurred_at` creible.
    $escenario = escenarioDeRevision();
    $titularId = AttendanceFixtures::employeeIdOf($escenario['employee']);
    $tarjeta = Credentials::issueFor($titularId)->toString();
    $codigo = codigoDeEmpleado($escenario['employee']);

    $yaRegistrado = Str::uuid7()->toString();
    $registradoDespues = Str::uuid7()->toString();

    DB::table('scan_events')->insert([
        'scan_id' => $yaRegistrado, 'device_id' => $escenario['device'], 'employee_id' => null,
        'occurred_at' => '2026-08-15 06:00:00+00', 'recorded_at' => '2026-08-15 06:00:01+00',
        'origin' => 'pin_kiosk', 'intent' => 'auto', 'result' => 'rejected_unknown',
        'flagged_for_review' => false, 'client_meta' => '{}',
    ]);

    avisoDeDescarte($escenario['token'], [
        // Sin dueño: un codigo que no existe.
        descartePorCodigo('EZZZZZZZZ'),
        // Ya estaba en el registro al recibir el aviso.
        descartePorCodigo($codigo, ['scan_id' => $yaRegistrado]),
        // Llega al registro despues del aviso (otro envio lo acepto).
        descartePorCodigo($codigo, ['scan_id' => $registradoDespues]),
        // Futuro: mas alla de la recepcion y de la tolerancia de desfase.
        descartePorCodigo($codigo, ['occurred_at' => '2026-08-16T09:00:00Z']),
        // Anterior a la emision de la tarjeta que lo atribuye.
        [
            'scan_id' => Str::uuid7()->toString(), 'occurred_at' => '2026-08-14T05:00:00Z', 'kind' => 'qr',
            'http_status' => 400, 'problem_type' => null, 'discarded_at' => '2026-08-15T07:10:00Z', 'qr_payload' => $tarjeta,
        ],
        // Mas antiguo que la ventana de revision (31 dias de serie).
        descartePorCodigo($codigo, ['occurred_at' => '2026-07-01T06:00:00Z']),
    ]);

    DB::table('scan_events')->insert([
        'scan_id' => $registradoDespues, 'device_id' => $escenario['device'], 'employee_id' => null,
        'occurred_at' => '2026-08-15 06:59:00+00', 'recorded_at' => '2026-08-15 10:05:00+00',
        'origin' => 'pin_kiosk', 'intent' => 'auto', 'result' => 'rejected_unknown',
        'flagged_for_review' => false, 'client_meta' => '{}',
    ]);

    revisionDiaria();

    expect(DB::table('discarded_scan_reports')->count())->toBe(6)
        ->and(incidenciasDe('discarded_scan'))->toBe([]);
})->group('RN-22', 'RF-PR-01', 'RS-03');

it('no abre nada con un aviso por codigo anterior al alta de la persona', function (): void {
    // F6, la cota del PIN: el alta (2026-01-01 en la zona del centro). Con una
    // ventana amplia para que lo unico que lo deje fuera sea el alta.
    $escenario = escenarioDeRevision();
    Config::set('attendance.discard_review_window_days', 400);

    avisoDeDescarte($escenario['token'], [
        descartePorCodigo(codigoDeEmpleado($escenario['employee']), ['occurred_at' => '2025-12-31T12:00:00Z']),
    ]);

    revisionDiaria();

    expect(incidenciasDe('discarded_scan'))->toBe([]);
})->group('RN-22', 'RF-PR-01');
