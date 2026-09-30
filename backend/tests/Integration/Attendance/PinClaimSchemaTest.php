<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\RejectedPinScans;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RN-19 en el esquema y en su adaptador de lectura (ADR-043).
 *
 * `scan_events_chk_pin_claim` es la ultima linea de defensa: un claim solo cabe
 * en un fichaje por PIN rechazado como `rejected_unknown` y sin empleado
 * resuelto. Y `EloquentRejectedPinScans` es lo que la revision diaria lee: que
 * filtre por claim, que respete la ventana de `recorded_at` en sus dos extremos
 * y que la subsanacion admita exactamente los seis resultados de RN-19.
 */

uses(RefreshDatabase::class);

const PIN_CLAIM_SCHEMA_AT = '2026-03-14 07:00:00+00';

/**
 * @return array{device: int, employee: string, employeeId: int, other: string, otherId: int}
 */
function pinClaimSchemaFixture(): array
{
    $scenario = AttendanceFixtures::scenario();
    $other = WorkforceFixtures::employee($scenario['site'], $scenario['department']);

    return [
        'device' => $scenario['device'],
        'employee' => $scenario['employee'],
        'employeeId' => AttendanceFixtures::employeeIdOf($scenario['employee']),
        'other' => $other,
        'otherId' => AttendanceFixtures::employeeIdOf($other),
    ];
}

/**
 * @param  array{device: int, ...}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function pinClaimSchemaInsert(array $fixture, array $overrides = []): string
{
    $scanId = Str::uuid7()->toString();

    DB::table('scan_events')->insert(array_merge([
        'scan_id' => $scanId,
        'device_id' => $fixture['device'],
        'employee_id' => null,
        'occurred_at' => PIN_CLAIM_SCHEMA_AT,
        'recorded_at' => PIN_CLAIM_SCHEMA_AT,
        'origin' => 'pin_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_unknown',
        'flagged_for_review' => false,
        'worked_minutes' => null,
        'client_meta' => '{}',
    ], $overrides));

    return $scanId;
}

function pinClaimSchemaExpectRejected(Closure $write): void
{
    try {
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('scan_events_chk_pin_claim');

        return;
    }

    Assert::fail('PostgreSQL acepto una fila que scan_events_chk_pin_claim tenia que rechazar.');
}

function pinClaimSchemaUtc(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

// --- I1 · el CHECK -------------------------------------------------------------

it('acepta la fila de un PIN rechazado con dueño', function (): void {
    $fixture = pinClaimSchemaFixture();

    $scanId = pinClaimSchemaInsert($fixture, ['claimed_employee_id' => $fixture['employeeId'], 'pin_lockout' => true]);

    $row = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($row?->claimed_employee_id)->toBe($fixture['employeeId'])
        ->and($row?->pin_lockout)->toBeTrue();
})->group('RN-19');

it('rechaza el claim fuera de un rechazo por PIN sin empleado', function (array $overrides): void {
    $fixture = pinClaimSchemaFixture();

    $overrides = array_map(
        static fn (mixed $value): mixed => $value === 'EMPLOYEE' ? $fixture['employeeId'] : $value,
        $overrides,
    );

    pinClaimSchemaExpectRejected(static function () use ($fixture, $overrides): void {
        pinClaimSchemaInsert($fixture, $overrides);
    });
})->with([
    'escaneo de tarjeta' => [['origin' => 'qr_kiosk', 'claimed_employee_id' => 'EMPLOYEE', 'pin_lockout' => false]],
    'fichaje aceptado' => [['result' => 'clock_in', 'worked_minutes' => 0, 'claimed_employee_id' => 'EMPLOYEE', 'pin_lockout' => false]],
    'con empleado resuelto' => [['employee_id' => 'EMPLOYEE', 'claimed_employee_id' => 'EMPLOYEE', 'pin_lockout' => false]],
    'bloqueo sin dueño' => [['pin_lockout' => true]],
    'dueño sin bloqueo anotado' => [['claimed_employee_id' => 'EMPLOYEE']],
])->group('RN-19');

// --- I3 · el adaptador ---------------------------------------------------------

it('lee solo los intentos con claim dentro de la ventana de recorded_at, en orden', function (): void {
    $fixture = pinClaimSchemaFixture();
    $claim = ['claimed_employee_id' => $fixture['employeeId'], 'pin_lockout' => false];

    // Los dos extremos entran; uno a cada lado, no.
    $atStart = pinClaimSchemaInsert($fixture, [...$claim, 'occurred_at' => '2026-03-14 07:05:00+00', 'recorded_at' => '2026-03-14 08:00:00+00']);
    $atEnd = pinClaimSchemaInsert($fixture, [...$claim, 'occurred_at' => '2026-03-14 07:00:00+00', 'recorded_at' => '2026-03-15 08:00:00+00', 'pin_lockout' => true]);
    pinClaimSchemaInsert($fixture, [...$claim, 'recorded_at' => '2026-03-14 07:59:59.999999+00']);
    pinClaimSchemaInsert($fixture, [...$claim, 'recorded_at' => '2026-03-15 08:00:00.000001+00']);
    // Sin claim: un codigo inexistente del mismo momento.
    pinClaimSchemaInsert($fixture, ['recorded_at' => '2026-03-14 12:00:00+00']);

    $attempts = app(RejectedPinScans::class)->rejectedBetween(
        pinClaimSchemaUtc('2026-03-14 08:00:00'),
        pinClaimSchemaUtc('2026-03-15 08:00:00'),
    );

    expect(array_map(static fn ($a): string => $a->scanId, $attempts))->toBe([$atEnd, $atStart])
        ->and($attempts[0]->claimantUuid)->toBe($fixture['employee'])
        ->and($attempts[0]->lockout)->toBeTrue()
        ->and($attempts[1]->lockout)->toBeFalse()
        ->and($attempts[0]->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($attempts[0]->syncDelaySeconds())->toBe(90000);
})->group('RN-19', 'RF-PR-01');

it('devuelve como subsanacion los seis resultados de RN-19 de esa persona y nada mas', function (): void {
    $fixture = pinClaimSchemaFixture();
    $mine = ['employee_id' => $fixture['employeeId'], 'origin' => 'qr_kiosk'];

    $recovering = [
        'clock_in' => '2026-03-14 07:00:00+00',
        'clock_out' => '2026-03-14 07:01:00+00',
        'break_start' => '2026-03-14 07:02:00+00',
        'break_end' => '2026-03-14 07:03:00+00',
        'rejected_debounce' => '2026-03-14 07:04:00+00',
        'rejected_out_of_order' => '2026-03-14 07:10:00+00',
    ];

    foreach ($recovering as $result => $at) {
        pinClaimSchemaInsert($fixture, [
            ...$mine,
            'result' => $result,
            'occurred_at' => $at,
            'worked_minutes' => in_array($result, ['rejected_out_of_order'], true) ? null : 0,
            'flagged_for_review' => $result === 'rejected_out_of_order',
        ]);
    }

    // Rechazos de credencial de la misma persona: no subsanan.
    foreach (['rejected_unknown', 'rejected_revoked', 'rejected_signature'] as $result) {
        pinClaimSchemaInsert($fixture, [...$mine, 'result' => $result, 'occurred_at' => '2026-03-14 07:05:00+00']);
    }

    // Otra persona dentro de la ventana, y la misma fuera de ella.
    pinClaimSchemaInsert($fixture, ['employee_id' => $fixture['otherId'], 'origin' => 'qr_kiosk', 'result' => 'clock_in', 'worked_minutes' => 0, 'occurred_at' => '2026-03-14 07:05:00+00']);
    pinClaimSchemaInsert($fixture, [...$mine, 'result' => 'clock_in', 'worked_minutes' => 0, 'occurred_at' => '2026-03-14 07:10:00.000001+00']);

    $instants = app(RejectedPinScans::class)->recoveringScansOf(
        $fixture['employee'],
        pinClaimSchemaUtc('2026-03-14 07:00:00'),
        pinClaimSchemaUtc('2026-03-14 07:10:00'),
    );

    expect(array_map(static fn (DateTimeImmutable $at): string => $at->format('H:i:s'), $instants))
        ->toBe(['07:00:00', '07:01:00', '07:02:00', '07:03:00', '07:04:00', '07:10:00']);
})->group('RN-19');
