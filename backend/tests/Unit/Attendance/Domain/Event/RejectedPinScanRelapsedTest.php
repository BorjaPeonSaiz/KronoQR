<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\RejectedPinScanRelapsed;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/*
 * La jornada con PIN rechazado que recae: algo subsanado y algo que no (RN-19,
 * enmienda del 27-10-2026 de ADR-057 §2). El evento solo transporta escalares
 * —`Compliance` no puede nombrar `WorkDate`— y no se puede construir sin lo que
 * el asiento de la reapertura deja escrito.
 */

const REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE = '0199f0c2-0000-7000-8000-000000000001';

/** @var array<string, int|string> */
const REJECTED_PIN_SCAN_RELAPSED_CONTEXT = ['scan_id' => 'scan-b', 'attempts' => 1, 'lockout_attempts' => 0];

it('anuncia la jornada que recae con escalares y en UTC', function (): void {
    $event = new RejectedPinScanRelapsed(
        REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE,
        3,
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        new DateTimeImmutable('2026-03-17 04:30:00', new DateTimeZone('UTC')),
        REJECTED_PIN_SCAN_RELAPSED_CONTEXT,
        ['scan-a', 'scan-b'],
        ['scan-c'],
    );

    expect($event->employeeUuid())->toBe(REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE)
        ->and($event->siteId())->toBe(3)
        ->and($event->workDate())->toBe('2026-03-14')
        ->and($event->context())->toBe(REJECTED_PIN_SCAN_RELAPSED_CONTEXT)
        ->and($event->eventName())->toBe('attendance.rejected_pin_scan_relapsed')
        ->and($event->occurredAt()->format('Y-m-d H:i:s'))->toBe('2026-03-17 04:30:00')
        ->and($event->attemptScanIds())->toBe(['scan-a', 'scan-b'])
        ->and($event->recoveringScanIds())->toBe(['scan-c']);
})->group('RN-19');

/**
 * @param  list<string>  $attempts
 * @param  list<string>  $recovering
 * @param  array<string, int|string>  $context
 * @param  class-string<Throwable>  $exception
 */
function rejectedPinScanRelapsedInvalid(string $employeeUuid, DateTimeImmutable $at, string $exception, array $attempts = ['scan-a'], array $recovering = ['scan-b'], array $context = REJECTED_PIN_SCAN_RELAPSED_CONTEXT): void
{
    expect(fn (): RejectedPinScanRelapsed => new RejectedPinScanRelapsed(
        $employeeUuid,
        3,
        WorkDate::fromIsoDate('2026-03-14', new DateTimeZone('Europe/Madrid')),
        $at,
        $context,
        $attempts,
        $recovering,
    ))->toThrow($exception);
}

it('no anuncia una recaida sin persona, sin intentos, sin fichaje que subsane, sin contexto o fuera de UTC', function (string $case): void {
    $utc = new DateTimeImmutable('2026-03-17 04:30:00', new DateTimeZone('UTC'));

    match ($case) {
        'sin persona' => rejectedPinScanRelapsedInvalid('', $utc, InvalidArgumentException::class),
        'sin intentos' => rejectedPinScanRelapsedInvalid(REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE, $utc, InvalidArgumentException::class, attempts: []),
        'sin fichaje que subsane' => rejectedPinScanRelapsedInvalid(REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE, $utc, InvalidArgumentException::class, recovering: []),
        'sin contexto' => rejectedPinScanRelapsedInvalid(REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE, $utc, InvalidArgumentException::class, context: []),
        'fuera de UTC' => rejectedPinScanRelapsedInvalid(
            REJECTED_PIN_SCAN_RELAPSED_EMPLOYEE,
            new DateTimeImmutable('2026-03-17 05:30:00', new DateTimeZone('Europe/Madrid')),
            InstantIsNotUtc::class,
        ),
        default => throw new LogicException($case),
    };
})->with([
    'sin persona',
    'sin intentos',
    'sin fichaje que subsane',
    'sin contexto',
    'fuera de UTC',
])->group('RN-19');
