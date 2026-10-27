<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\RejectedPinScanRecovered;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinStatus;
use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/*
 * RN-19: cuando un PIN rechazado queda subsanado por un fichaje de la misma
 * persona (ADR-043, doc 01 §4 «Sobre RN-19»).
 *
 * La ventana es [intento, intento + 600 s] con los DOS extremos incluidos. Los
 * bordes son justo donde se equivoca una comparacion escrita a mano, y por eso
 * se prueba cada uno por separado.
 */

function pinRecoveryAttempt(string $occurredAt = '2026-03-14 07:00:00', string $recordedAt = '2026-03-14 07:00:00', bool $lockout = false): RejectedPinAttempt
{
    return new RejectedPinAttempt(
        scanId: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        claimantUuid: '0199f0c2-0000-7000-8000-000000000001',
        occurredAt: new DateTimeImmutable($occurredAt, new DateTimeZone('UTC')),
        recordedAt: new DateTimeImmutable($recordedAt, new DateTimeZone('UTC')),
        lockout: $lockout,
    );
}

function pinRecoveryAt(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

it('decide la subsanacion en los bordes de la ventana', function (array $accepted, bool $expected): void {
    $policy = new PinAttemptRecoveryPolicy;

    expect($policy->isRecovered(pinRecoveryAttempt(), array_values(array_map(pinRecoveryAt(...), $accepted))))->toBe($expected);
})->with([
    'a +0 s subsana' => [['2026-03-14 07:00:00'], true],
    'a +600 s subsana' => [['2026-03-14 07:10:00'], true],
    'a +601 s no' => [['2026-03-14 07:10:01'], false],
    'a +600,5 s no' => [['2026-03-14 07:10:00.500000'], false],
    'a -1 s no' => [['2026-03-14 06:59:59'], false],
    'lista vacia no' => [[], false],
    'basta uno dentro' => [['2026-03-14 06:00:00', '2026-03-14 07:05:00'], true],
])->group('RN-19');

it('fija la ventana en 600 segundos, sin configuracion', function (): void {
    expect(PinAttemptRecoveryPolicy::RECOVERY_WINDOW_SECONDS)->toBe(600);
})->group('RN-19');

it('rechaza un intento sin scan_id o sin dueño', function (string $scanId, string $claimant): void {
    expect(fn (): RejectedPinAttempt => new RejectedPinAttempt(
        scanId: $scanId,
        claimantUuid: $claimant,
        occurredAt: pinRecoveryAt('2026-03-14 07:00:00'),
        recordedAt: pinRecoveryAt('2026-03-14 07:00:00'),
        lockout: false,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'sin scan_id' => ['', '0199f0c2-0000-7000-8000-000000000001'],
    'sin dueño' => ['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', ''],
])->group('RN-19');

it('rechaza instantes que no estan en UTC', function (): void {
    expect(fn (): RejectedPinAttempt => new RejectedPinAttempt(
        scanId: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        claimantUuid: '0199f0c2-0000-7000-8000-000000000001',
        occurredAt: new DateTimeImmutable('2026-03-14 08:00:00', new DateTimeZone('Europe/Madrid')),
        recordedAt: pinRecoveryAt('2026-03-14 07:00:00'),
        lockout: false,
    ))->toThrow(InstantIsNotUtc::class);
})->group('RN-19');

it('mide el retraso de sincronizacion con signo', function (string $occurredAt, string $recordedAt, int $expected): void {
    expect(pinRecoveryAttempt($occurredAt, $recordedAt)->syncDelaySeconds())->toBe($expected);
})->with([
    'cola que drena dos horas tarde' => ['2026-03-14 05:00:00', '2026-03-14 07:00:00', 7200],
    'en el acto' => ['2026-03-14 07:00:00', '2026-03-14 07:00:00', 0],
    'reloj del quiosco adelantado' => ['2026-03-14 07:01:30', '2026-03-14 07:00:00', -90],
])->group('RN-19');

it('decide que desenlaces subsanan: toda accion aceptada, el anti-rebote y el irreconciliable', function (ClockingAction|ScanRejectionReason $outcome, bool $expected): void {
    expect(PinAttemptRecoveryPolicy::recovers($outcome))->toBe($expected);
})->with([
    'entrada' => [ClockingAction::CLOCK_IN, true],
    'salida' => [ClockingAction::CLOCK_OUT, true],
    'inicio de pausa' => [ClockingAction::BREAK_START, true],
    'fin de pausa' => [ClockingAction::BREAK_END, true],
    'anti-rebote' => [ScanRejectionReason::DEBOUNCE, true],
    'irreconciliable de RN-18' => [ScanRejectionReason::OUT_OF_ORDER, true],
    'credencial desconocida' => [ScanRejectionReason::UNKNOWN_CREDENTIAL, false],
    'credencial revocada' => [ScanRejectionReason::REVOKED_CREDENTIAL, false],
    'firma invalida' => [ScanRejectionReason::INVALID_SIGNATURE, false],
])->group('RN-19');

it('cierra la ventana 600 segundos despues del intento', function (): void {
    expect((new PinAttemptRecoveryPolicy)->windowEndsAt(pinRecoveryAttempt('2026-03-14 07:00:00.250000'))->format('Y-m-d H:i:s.u'))
        ->toBe('2026-03-14 07:10:00.250000');
})->group('RN-19');

it('espera a que se cierre la ventana antes de dar un intento por no subsanado', function (array $accepted, string $now, RejectedPinStatus $expected): void {
    $status = (new PinAttemptRecoveryPolicy)->statusOf(
        pinRecoveryAttempt(),
        array_values(array_map(pinRecoveryAt(...), $accepted)),
        pinRecoveryAt($now),
    );

    expect($status)->toBe($expected);
})->with([
    'pasada con la ventana abierta y sin fichaje: espera' => [[], '2026-03-14 07:05:00', RejectedPinStatus::PENDING],
    'pasada en el mismo instante del intento: espera' => [[], '2026-03-14 07:00:00', RejectedPinStatus::PENDING],
    'pasada justo en el cierre (+600 s): todavia espera' => [[], '2026-03-14 07:10:00', RejectedPinStatus::PENDING],
    'pasada a +600,000001 s: sin subsanar' => [[], '2026-03-14 07:10:00.000001', RejectedPinStatus::UNRECOVERED],
    'pasada al dia siguiente sin fichaje: sin subsanar' => [[], '2026-03-15 04:30:00', RejectedPinStatus::UNRECOVERED],
    'fichaje dentro con la ventana abierta: subsanado ya' => [['2026-03-14 07:01:00'], '2026-03-14 07:05:00', RejectedPinStatus::RECOVERED],
    'fichaje dentro con la ventana cerrada: subsanado' => [['2026-03-14 07:10:00'], '2026-03-15 04:30:00', RejectedPinStatus::RECOVERED],
    'fichaje fuera con la ventana cerrada: sin subsanar' => [['2026-03-14 07:10:01'], '2026-03-15 04:30:00', RejectedPinStatus::UNRECOVERED],
])->group('RN-19');

it('no juzga con un instante de pasada que no esta en UTC', function (): void {
    expect(fn (): RejectedPinStatus => (new PinAttemptRecoveryPolicy)->statusOf(
        pinRecoveryAttempt(),
        [],
        new DateTimeImmutable('2026-03-15 05:30:00', new DateTimeZone('Europe/Madrid')),
    ))->toThrow(InstantIsNotUtc::class);
})->group('RN-19');

it('da a la jornada el estado de sus intentos: uno sin subsanar basta, y solo todos subsanados la subsanan', function (array $attempts, RejectedPinStatus $expected): void {
    expect(RejectedPinStatus::ofWorkDay(array_values($attempts)))->toBe($expected);
})->with([
    'uno subsanado' => [[RejectedPinStatus::RECOVERED], RejectedPinStatus::RECOVERED],
    'todos subsanados' => [[RejectedPinStatus::RECOVERED, RejectedPinStatus::RECOVERED], RejectedPinStatus::RECOVERED],
    'uno pendiente' => [[RejectedPinStatus::PENDING], RejectedPinStatus::PENDING],
    'subsanado y pendiente' => [[RejectedPinStatus::RECOVERED, RejectedPinStatus::PENDING], RejectedPinStatus::PENDING],
    'pendiente y subsanado' => [[RejectedPinStatus::PENDING, RejectedPinStatus::RECOVERED], RejectedPinStatus::PENDING],
    'uno sin subsanar' => [[RejectedPinStatus::UNRECOVERED], RejectedPinStatus::UNRECOVERED],
    'sin subsanar entre subsanados' => [[RejectedPinStatus::RECOVERED, RejectedPinStatus::UNRECOVERED, RejectedPinStatus::RECOVERED], RejectedPinStatus::UNRECOVERED],
    'sin subsanar y pendiente' => [[RejectedPinStatus::PENDING, RejectedPinStatus::UNRECOVERED], RejectedPinStatus::UNRECOVERED],
])->group('RN-19');

it('no da estado a una jornada sin intentos', function (): void {
    expect(fn (): RejectedPinStatus => RejectedPinStatus::ofWorkDay([]))->toThrow(InvalidArgumentException::class);
})->group('RN-19');

it('anuncia la jornada subsanada con escalares y en UTC', function (): void {
    $event = new RejectedPinScanRecovered(
        '0199f0c2-0000-7000-8000-000000000001',
        3,
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        pinRecoveryAt('2026-03-15 04:30:00'),
    );

    expect($event->employeeUuid())->toBe('0199f0c2-0000-7000-8000-000000000001')
        ->and($event->siteId())->toBe(3)
        ->and($event->workDate())->toBe('2026-03-14')
        ->and($event->eventName())->toBe('attendance.rejected_pin_scan_recovered')
        ->and($event->occurredAt()->format('Y-m-d H:i:s'))->toBe('2026-03-15 04:30:00');
})->group('RN-19');

it('no anuncia una jornada subsanada sin persona ni con un instante fuera de UTC', function (string $employeeUuid, DateTimeImmutable $at, string $exception): void {
    expect(fn (): RejectedPinScanRecovered => new RejectedPinScanRecovered(
        $employeeUuid,
        3,
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        $at,
    ))->toThrow($exception);
})->with([
    'sin persona' => ['', new DateTimeImmutable('2026-03-15 04:30:00', new DateTimeZone('UTC')), InvalidArgumentException::class],
    'fuera de UTC' => ['0199f0c2-0000-7000-8000-000000000001', new DateTimeImmutable('2026-03-15 05:30:00', new DateTimeZone('Europe/Madrid')), InstantIsNotUtc::class],
])->group('RN-19');
