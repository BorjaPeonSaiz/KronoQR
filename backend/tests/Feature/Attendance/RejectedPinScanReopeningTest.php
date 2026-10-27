<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * **RN-19: la jornada que recae reabre la incidencia que cerro el sistema**
 * (enmienda del 27-10-2026 de ADR-057 §2).
 *
 * El escenario de los tres quioscos, de punta a punta y con la hora de Madrid
 * (UTC+1 en marzo):
 *
 *   - A1, PIN erroneo a las 08:00 del 14 en K1, que tiene red;
 *   - su subsanacion, el PIN bueno a las 08:03 en K2, sin red;
 *   - A2, otro PIN erroneo a las 22:00 en K3, tambien sin red;
 *   - la pasada del 15 abre la incidencia de la jornada del 14;
 *   - K2 sincroniza y la pasada del 16 la cierra sin persona;
 *   - K3 sincroniza y la pasada del 17 la reabre, con su asiento de sistema.
 *
 * Antes de la enmienda, el hallazgo de A2 chocaba con la incidencia cerrada en
 * `one_incident_per_finding` y se perdia sin dejar rastro.
 */

uses(RefreshDatabase::class);

const REJECTED_PIN_SCAN_REOPENING_GOOD = '481902';

const REJECTED_PIN_SCAN_REOPENING_BAD = '481903';

/**
 * Persona con PIN en un departamento con responsable, y tres quioscos.
 *
 * @return array{employee: string, code: string, publicKey: string, kiosks: array{K1: string, K2: string, K3: string}}
 */
function reaperturaPinEscenario(): array
{
    $scenario = AttendanceFixtures::scenario();
    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Department::query()->whereKey($scenario['department'])->update(['manager_user_id' => $manager->id]);

    EmployeePins::issue($scenario['employee'], REJECTED_PIN_SCAN_REOPENING_GOOD);

    app()->instance(ScanMetrics::class, new RecordingScanMetrics);
    app(Cache::class)->clear();
    app()->forgetInstance(PinAttempts::class);

    $k2 = AttendanceFixtures::device($scenario['site'], 'Cocina');
    $k3 = AttendanceFixtures::device($scenario['site'], 'Pisos');

    return [
        'employee' => $scenario['employee'],
        'code' => EmployeePins::codeOf($scenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
        'kiosks' => [
            'K1' => $scenario['token'],
            'K2' => AttendanceFixtures::tokenFor($k2['id']),
            'K3' => AttendanceFixtures::tokenFor($k3['id']),
        ],
    ];
}

/**
 * Lo que un quiosco envia —en su momento o al sincronizar su cola—: el
 * `occurred_at` es el de la tablet; el reloj del servidor es el de la entrega.
 *
 * @param  array{code: string, publicKey: string, kiosks: array{K1: string, K2: string, K3: string}, ...}  $scenario
 * @return TestResponse<Response>
 */
function reaperturaPinEnvia(array $scenario, string $kiosk, string $scanId, string $pin, string $occurredAt, string $deliveredAt): TestResponse
{
    FrozenTime::at($deliveredAt);

    return Api::as($scenario['kiosks'][$kiosk])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'employee_code' => $scenario['code'],
            'pin_sealed' => EmployeePins::seal($pin, $scenario['publicKey']),
        ]);
}

/**
 * Una pasada de la revision a las 03:30 UTC, con lo que dejo en el log.
 *
 * @return array<string, mixed>
 */
function reaperturaPinPasada(string $at): array
{
    Notification::fake();
    FrozenTime::at($at);

    /** @var array<string, mixed> $logged */
    $logged = [];
    Log::listen(static function (MessageLogged $message) use (&$logged): void {
        if ($message->message === 'attendance.incident_detection') {
            $logged = $message->context;
        }
    });

    expect(Artisan::call('attendance:detect-incidents'))->toBe(0);

    return $logged;
}

/** @return object{id: int, status: string, resolved_at: string|null, resolved_by_user_id: int|null, resolution_note: string|null, context: string} */
function reaperturaPinIncidencia(): object
{
    $rows = DB::table('incidents')->where('type', 'rejected_pin_scan')
        ->get(['id', 'status', 'resolved_at', 'resolved_by_user_id', 'resolution_note', 'context']);

    expect($rows)->toHaveCount(1);

    /** @var object{id: int, status: string, resolved_at: string|null, resolved_by_user_id: int|null, resolution_note: string|null, context: string} $row */
    $row = $rows->first();

    return $row;
}

/**
 * @return array<string, mixed>
 */
function reaperturaPinJson(string $json): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('reabre con asiento de sistema la incidencia que cerro la subsanacion cuando otro quiosco trae un intento sin subsanar', function (): void {
    $scenario = reaperturaPinEscenario();
    $a1 = Str::uuid7()->toString();
    $recovery = Str::uuid7()->toString();
    $a2 = Str::uuid7()->toString();

    // A1 en K1, con red: llega en el momento.
    reaperturaPinEnvia($scenario, 'K1', $a1, REJECTED_PIN_SCAN_REOPENING_BAD, '2026-03-14T07:00:00Z', '2026-03-14 07:00:00')->assertStatus(422);

    // D+1: la pasada abre. K2 y K3 siguen sin red.
    reaperturaPinPasada('2026-03-15 03:30:00');

    $opened = reaperturaPinIncidencia();

    expect($opened->status)->toBe('open')
        ->and(reaperturaPinJson($opened->context)['scan_id'])->toBe($a1);

    // K2 sincroniza la subsanacion de las 08:03; D+2: la pasada cierra.
    reaperturaPinEnvia($scenario, 'K2', $recovery, REJECTED_PIN_SCAN_REOPENING_GOOD, '2026-03-14T07:03:00Z', '2026-03-15 10:00:00')->assertOk();
    reaperturaPinPasada('2026-03-16 03:30:00');

    $closed = reaperturaPinIncidencia();

    expect($closed->id)->toBe($opened->id)
        ->and($closed->status)->toBe('resolved')
        ->and($closed->resolved_by_user_id)->toBeNull()
        ->and($closed->resolution_note)->toBeNull();

    // K3 sincroniza A2, de las 22:00, que nadie subsano; D+3: la pasada reabre.
    reaperturaPinEnvia($scenario, 'K3', $a2, REJECTED_PIN_SCAN_REOPENING_BAD, '2026-03-14T21:00:00Z', '2026-03-16 10:00:00')->assertStatus(422);
    $logged = reaperturaPinPasada('2026-03-17 03:30:00');

    $reopened = reaperturaPinIncidencia();
    $context = reaperturaPinJson($reopened->context);

    expect($reopened->id)->toBe($opened->id)
        ->and($reopened->status)->toBe('open')
        ->and($reopened->resolved_at)->toBeNull()
        ->and($reopened->resolved_by_user_id)->toBeNull()
        ->and($reopened->resolution_note)->toBeNull()
        // El contexto cuenta lo de ahora: A2, sin subsanar.
        ->and($context['scan_id'])->toBe($a2)
        ->and($context['attempts'])->toBe(1)
        ->and($context['lockout_attempts'])->toBe(0)
        // La pasada lo cuenta como anuncio, en el log y en su salida.
        ->and($logged['relapsed_work_days'])->toBe(1)
        ->and(Artisan::output())->toContain(trans('console.detect_incidents.relapsed', ['count' => 1]));

    $asientos = DB::table('audit_log')
        ->where('action', 'incident.reopened')
        ->where('subject_type', 'incident')
        ->where('subject_id', $opened->id)
        ->get();

    expect($asientos)->toHaveCount(1);

    $payload = reaperturaPinJson((string) $asientos->first()?->payload);

    expect($asientos->first()?->actor_type)->toBe('system')
        ->and($asientos->first()?->actor_id)->toBeNull()
        ->and($payload)->toMatchArray([
            'employee_uuid' => $scenario['employee'],
            'type' => 'rejected_pin_scan',
            'work_date' => '2026-03-14',
            'outcome' => 'open',
            'reopening' => 'unrecovered_attempt_after_recovery',
            // Quien audite la reapertura puede ir a `scan_events` con estos.
            'attempt_scan_ids' => [$a1, $a2],
            'recovering_scan_ids' => [$recovery],
        ])
        ->and($payload['previous_context'])->toMatchArray(['scan_id' => $a1])
        ->and($payload['context'])->toMatchArray(['scan_id' => $a2])
        ->and($payload['previous_resolved_at'])->toBeString();

    // Ningun nombre en el asiento (regla dura 21).
    $name = DB::table('employees')->where('uuid', $scenario['employee'])->value('first_name');

    \assert(is_string($name));

    expect((string) $asientos->first()?->payload)->not->toContain($name);

    // D+4: una pasada mas no reabre otra vez ni abre otra incidencia.
    reaperturaPinPasada('2026-03-18 03:30:00');

    expect(reaperturaPinIncidencia()->status)->toBe('open')
        ->and(DB::table('audit_log')->where('action', 'incident.reopened')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'incident.resolved')->count())->toBe(1);
})->group('RN-19', 'RF-PR-01', 'RL-04', 'RF-AT-10');

it('no reabre la incidencia que resolvio una persona aunque llegue otro intento sin subsanar', function (): void {
    $scenario = reaperturaPinEscenario();

    reaperturaPinEnvia($scenario, 'K1', Str::uuid7()->toString(), REJECTED_PIN_SCAN_REOPENING_BAD, '2026-03-14T07:00:00Z', '2026-03-14 07:00:00')->assertStatus(422);
    reaperturaPinPasada('2026-03-15 03:30:00');

    $resolver = ManagementUsers::withRole(UserRole::RRHH);

    DB::table('incidents')->where('type', 'rejected_pin_scan')->update([
        'status' => 'resolved',
        'resolved_at' => '2026-03-15 09:00:00+00',
        'resolved_by_user_id' => $resolver->id,
        'resolution_note' => 'Completado a mano con el parte de turno.',
    ]);

    reaperturaPinEnvia($scenario, 'K2', Str::uuid7()->toString(), REJECTED_PIN_SCAN_REOPENING_GOOD, '2026-03-14T07:03:00Z', '2026-03-15 10:00:00')->assertOk();
    reaperturaPinEnvia($scenario, 'K3', Str::uuid7()->toString(), REJECTED_PIN_SCAN_REOPENING_BAD, '2026-03-14T21:00:00Z', '2026-03-15 11:00:00')->assertStatus(422);
    $logged = reaperturaPinPasada('2026-03-16 03:30:00');

    $incident = reaperturaPinIncidencia();

    // La pasada anuncio la recaida, pero lo que trabajo una persona no se toca.
    expect($logged['relapsed_work_days'])->toBe(1)
        ->and($incident->status)->toBe('resolved')
        ->and($incident->resolved_by_user_id)->toBe($resolver->id)
        ->and($incident->resolution_note)->toBe('Completado a mano con el parte de turno.')
        ->and(DB::table('audit_log')->where('action', 'incident.reopened')->count())->toBe(0);
})->group('RN-19', 'RF-PR-01', 'RN-13');
