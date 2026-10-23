<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\UnprocessableScanDiscarded;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;

/*
 * **De donde vino un fichaje descartado** (RN-22, ADR-061): el quiosco que lo
 * aviso o el servidor que lo dio por imposible. La incidencia es la misma; su
 * `context` dice cual, y los intentos solo existen en el segundo caso.
 */

function descarteConOrigen(?int $intentosDelServidor, ?string $problemType = null): DiscardedScan
{
    $utc = new DateTimeZone('UTC');

    return new DiscardedScan(
        scanId: '0199f0c2-0000-7000-8000-0000000000c1',
        ownerUuid: '0199f0c2-0000-7000-8000-0000000000b1',
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: ScanOrigin::PIN_KIOSK,
        occurredAt: new DateTimeImmutable('2026-08-15 07:00:00', $utc),
        recordedAt: new DateTimeImmutable('2026-08-15 08:05:00', $utc),
        httpStatus: 422,
        problemType: $problemType,
        attribution: DiscardedScanAttributionMethod::EMPLOYEE_CODE,
        credentialIssuedAt: null,
        ownerHiredOn: '2026-08-01',
        serverAttempts: $intentosDelServidor,
    );
}

it('dice kiosk cuando no hay intentos del servidor y server cuando los hay', function (): void {
    expect(descarteConOrigen(null)->source())->toBe('kiosk')
        ->and(descarteConOrigen(1)->source())->toBe('server')
        ->and(descarteConOrigen(10)->serverAttempts)->toBe(10);
})->group('RN-22');

it('no admite un descarte del servidor sin ningun intento', function (int $intentos): void {
    expect(static fn (): DiscardedScan => descarteConOrigen($intentos))->toThrow(InvalidArgumentException::class);
})->with([0, -1])->group('RN-22');

it('reconoce el problema del descarte del servidor en el catalogo cerrado', function (): void {
    expect(descarteConOrigen(10, 'urn:kronoqr:problem:scan-discarded')->problem())->toBe('scan-discarded');
})->group('RN-22');

it('fecha el evento del descarte cuando lo decide el servidor, no cuando ocurrio el fichaje', function (): void {
    $utc = new DateTimeZone('UTC');
    $evento = new UnprocessableScanDiscarded(
        scanId: '0199f0c2-0000-7000-8000-0000000000c1',
        deviceId: 7,
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: 'qr_kiosk',
        scannedAt: new DateTimeImmutable('2026-08-15 07:00:00', $utc),
        discardedAt: new DateTimeImmutable('2026-08-15 08:05:00', $utc),
        firstFailedAt: new DateTimeImmutable('2026-08-15 07:01:00', $utc),
        attempts: 10,
        attribution: 'none',
        employeeUuid: null,
    );

    expect($evento->occurredAt()->format('c'))->toBe('2026-08-15T08:05:00+00:00')
        ->and($evento->eventName())->toBe('attendance.unprocessable_scan_discarded');
})->group('RN-22', 'RL-04');

it('no admite un aviso con dueño sin scan_id o sin dueño', function (string $scanId, string $owner): void {
    $utc = new DateTimeZone('UTC');

    expect(static fn (): DiscardedScan => new DiscardedScan(
        scanId: $scanId,
        ownerUuid: $owner,
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: ScanOrigin::PIN_KIOSK,
        occurredAt: new DateTimeImmutable('2026-08-15 07:00:00', $utc),
        recordedAt: new DateTimeImmutable('2026-08-15 08:05:00', $utc),
        httpStatus: 422,
        problemType: null,
        attribution: DiscardedScanAttributionMethod::EMPLOYEE_CODE,
        credentialIssuedAt: null,
        ownerHiredOn: '2026-08-01',
    ))->toThrow(InvalidArgumentException::class, 'scan_id y su dueño');
})->with([
    'sin scan_id' => ['', '0199f0c2-0000-7000-8000-0000000000b1'],
    'sin dueño' => ['0199f0c2-0000-7000-8000-0000000000c1', ''],
])->group('RN-22');

it('exige los dos instantes en UTC', function (string $campo): void {
    $utc = new DateTimeZone('UTC');
    $madrid = new DateTimeZone('Europe/Madrid');

    expect(static fn (): DiscardedScan => new DiscardedScan(
        scanId: '0199f0c2-0000-7000-8000-0000000000c1',
        ownerUuid: '0199f0c2-0000-7000-8000-0000000000b1',
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: ScanOrigin::PIN_KIOSK,
        occurredAt: new DateTimeImmutable('2026-08-15 07:00:00', $campo === 'occurredAt' ? $madrid : $utc),
        recordedAt: new DateTimeImmutable('2026-08-15 08:05:00', $campo === 'recordedAt' ? $madrid : $utc),
        httpStatus: 422,
        problemType: null,
        attribution: DiscardedScanAttributionMethod::EMPLOYEE_CODE,
        credentialIssuedAt: null,
        ownerHiredOn: '2026-08-01',
    ))->toThrow(InstantIsNotUtc::class, $campo);
})->with(['occurredAt', 'recordedAt'])->group('RN-22', 'RL-01');
