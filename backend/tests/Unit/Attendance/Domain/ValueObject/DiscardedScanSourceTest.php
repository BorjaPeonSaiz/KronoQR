<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\UnprocessableScanDiscarded;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScan;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttribution;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanAttributionMethod;
use App\Modules\Attendance\Domain\ValueObject\DiscardedScanSource;
use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;

/*
 * **De donde vino un fichaje descartado** (RN-22, ADR-061): el quiosco que lo
 * aviso o el servidor que lo dio por imposible. Dos constructores con nombre; el
 * del servidor exige sus intentos, el `422` y `scan-discarded`, y es el unico que
 * admite la atribucion por la huella del contenido.
 */

const DESCARTE_ORIGEN_TIPO = 'urn:kronoqr:problem:scan-discarded';

function instanteUtc(string $momento): DateTimeImmutable
{
    return new DateTimeImmutable($momento, new DateTimeZone('UTC'));
}

function avisoDelQuiosco(
    string $scanId = '0199f0c2-0000-7000-8000-0000000000c1',
    string $owner = '0199f0c2-0000-7000-8000-0000000000b1',
    DiscardedScanAttributionMethod $atribucion = DiscardedScanAttributionMethod::EMPLOYEE_CODE,
    ?DateTimeImmutable $ocurrido = null,
    ?DateTimeImmutable $recibido = null,
): DiscardedScan {
    return DiscardedScan::reportedByKiosk(
        scanId: $scanId,
        ownerUuid: $owner,
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: ScanOrigin::PIN_KIOSK,
        occurredAt: $ocurrido ?? instanteUtc('2026-08-15 07:00:00'),
        recordedAt: $recibido ?? instanteUtc('2026-08-15 08:05:00'),
        httpStatus: 400,
        problemType: null,
        attribution: $atribucion,
        credentialIssuedAt: null,
        ownerHiredOn: '2026-08-01',
    );
}

function descarteDelServidor(
    int $intentos = 10,
    int $httpStatus = 422,
    ?string $problemType = DESCARTE_ORIGEN_TIPO,
    DiscardedScanAttributionMethod $atribucion = DiscardedScanAttributionMethod::FINGERPRINT_MATCH,
): DiscardedScan {
    return DiscardedScan::discardedByServer(
        scanId: '0199f0c2-0000-7000-8000-0000000000c1',
        ownerUuid: '0199f0c2-0000-7000-8000-0000000000b1',
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: ScanOrigin::QR_KIOSK,
        occurredAt: instanteUtc('2026-08-15 07:00:00'),
        recordedAt: instanteUtc('2026-08-15 08:05:00'),
        httpStatus: $httpStatus,
        problemType: $problemType,
        attribution: $atribucion,
        credentialIssuedAt: null,
        ownerHiredOn: '2026-08-01',
        attempts: $intentos,
    );
}

it('distingue el aviso del quiosco del descarte del servidor', function (): void {
    expect(avisoDelQuiosco()->source)->toBe(DiscardedScanSource::Kiosk)
        ->and(avisoDelQuiosco()->serverAttempts)->toBeNull()
        ->and(descarteDelServidor()->source)->toBe(DiscardedScanSource::Server)
        ->and(descarteDelServidor(intentos: 1)->serverAttempts)->toBe(1)
        ->and(descarteDelServidor()->problem())->toBe('scan-discarded');
})->group('RN-22');

it('no admite un descarte del servidor sin ningun intento', function (int $intentos): void {
    expect(static fn (): DiscardedScan => descarteDelServidor(intentos: $intentos))->toThrow(InvalidArgumentException::class);
})->with([0, -1])->group('RN-22');

it('exige al descarte del servidor el 422 y el problema scan-discarded', function (int $status, ?string $tipo): void {
    expect(static fn (): DiscardedScan => descarteDelServidor(httpStatus: $status, problemType: $tipo))
        ->toThrow(InvalidArgumentException::class, '422 scan-discarded');
})->with([
    'otro codigo' => [400, DESCARTE_ORIGEN_TIPO],
    'otro problema' => [422, 'urn:kronoqr:problem:scan-rejected'],
    'sin problema' => [422, null],
])->group('RN-22');

it('solo admite la atribucion por huella en el descarte del servidor', function (): void {
    expect(static fn (): DiscardedScan => avisoDelQuiosco(atribucion: DiscardedScanAttributionMethod::FINGERPRINT_MATCH))
        ->toThrow(InvalidArgumentException::class, 'huella')
        ->and(descarteDelServidor()->attribution)->toBe(DiscardedScanAttributionMethod::FINGERPRINT_MATCH);
})->group('RN-22');

it('no admite un aviso con dueño sin scan_id o sin dueño', function (string $scanId, string $owner): void {
    expect(static fn (): DiscardedScan => avisoDelQuiosco(scanId: $scanId, owner: $owner))
        ->toThrow(InvalidArgumentException::class, 'scan_id y su dueño');
})->with([
    'sin scan_id' => ['', '0199f0c2-0000-7000-8000-0000000000b1'],
    'sin dueño' => ['0199f0c2-0000-7000-8000-0000000000c1', ''],
])->group('RN-22');

it('exige los dos instantes en UTC', function (string $campo): void {
    $madrid = new DateTimeImmutable('2026-08-15 07:00:00', new DateTimeZone('Europe/Madrid'));

    expect(static fn (): DiscardedScan => avisoDelQuiosco(
        ocurrido: $campo === 'occurredAt' ? $madrid : null,
        recibido: $campo === 'recordedAt' ? $madrid : null,
    ))->toThrow(InstantIsNotUtc::class, $campo);
})->with(['occurredAt', 'recordedAt'])->group('RN-22', 'RL-01');

it('fecha el evento del descarte cuando lo decide el servidor y exige UTC', function (): void {
    $evento = static fn (DateTimeImmutable $escaneo, DateTimeImmutable $descarte): UnprocessableScanDiscarded => new UnprocessableScanDiscarded(
        scanId: '0199f0c2-0000-7000-8000-0000000000c1',
        deviceId: 7,
        deviceUuid: '0199f0c2-0000-7000-8000-0000000000d1',
        origin: ScanOrigin::QR_KIOSK,
        scannedAt: $escaneo,
        discardedAt: $descarte,
        history: new ScanFailureHistory(10, instanteUtc('2026-08-15 07:01:00')),
        attribution: DiscardedScanAttributionMethod::NONE,
        employeeUuid: null,
    );

    $bueno = $evento(instanteUtc('2026-08-15 07:00:00'), instanteUtc('2026-08-15 08:05:00'));
    $madrid = new DateTimeImmutable('2026-08-15 10:05:00', new DateTimeZone('Europe/Madrid'));

    expect($bueno->occurredAt()->format('c'))->toBe('2026-08-15T08:05:00+00:00')
        ->and($bueno->eventName())->toBe('attendance.unprocessable_scan_discarded')
        ->and(static fn (): UnprocessableScanDiscarded => $evento($madrid, instanteUtc('2026-08-15 08:05:00')))->toThrow(InstantIsNotUtc::class, 'scannedAt')
        ->and(static fn (): UnprocessableScanDiscarded => $evento(instanteUtc('2026-08-15 07:00:00'), $madrid))->toThrow(InstantIsNotUtc::class, 'discardedAt');
})->group('RN-22', 'RL-04');

it('no atribuye a un dueño vacio por ninguna via, y solo la tarjeta autentica', function (): void {
    $emision = instanteUtc('2026-01-01 00:00:00');

    expect(static fn () => DiscardedScanAttribution::credential('', $emision))->toThrow(InvalidArgumentException::class)
        ->and(static fn () => DiscardedScanAttribution::employeeCode(''))->toThrow(InvalidArgumentException::class)
        ->and(static fn () => DiscardedScanAttribution::fingerprintMatch(''))->toThrow(InvalidArgumentException::class)
        ->and(DiscardedScanAttribution::credential('0199f0c2-0000-7000-8000-0000000000b1', $emision)->authenticates())->toBeTrue()
        ->and(DiscardedScanAttribution::employeeCode('0199f0c2-0000-7000-8000-0000000000b1')->authenticates())->toBeFalse()
        ->and(DiscardedScanAttribution::fingerprintMatch('0199f0c2-0000-7000-8000-0000000000b1')->authenticates())->toBeFalse();
})->group('RN-22');
