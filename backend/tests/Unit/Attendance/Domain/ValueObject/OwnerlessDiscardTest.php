<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Policy\DiscardedScanReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\UnattributedServerDiscard;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;

/*
 * **El descarte del servidor sin atribuir, y su hallazgo sin persona** (RN-22,
 * ADR-061 A2): solo un `discarded_scan` puede ir sin persona, y entonces por su
 * quiosco; dentro de la misma ventana creible que los demas.
 */

function sinAtribuir(string $ocurrido = '2026-08-15 07:00:00', string $recibido = '2026-08-15 08:05:00', int $intentos = 10, string $quiosco = '0199f0c2-0000-7000-8000-0000000000d1', string $scanId = '0199f0c2-0000-7000-8000-0000000000c1'): UnattributedServerDiscard
{
    return new UnattributedServerDiscard(
        scanId: $scanId,
        deviceUuid: $quiosco,
        origin: ScanOrigin::QR_KIOSK,
        occurredAt: new DateTimeImmutable($ocurrido, new DateTimeZone('UTC')),
        recordedAt: new DateTimeImmutable($recibido, new DateTimeZone('UTC')),
        attempts: $intentos,
    );
}

function hallazgo(AnomalyType $type, ?string $employee, ?string $device): DetectedAnomaly
{
    return new DetectedAnomaly(
        type: $type,
        employeeUuid: $employee,
        siteId: 1,
        workDate: WorkDate::fromIsoDate('2026-08-15', new DateTimeZone('Europe/Madrid')),
        shiftEntryUuid: null,
        detectedAt: new DateTimeImmutable('2026-08-16 03:30:00', new DateTimeZone('UTC')),
        deviceUuid: $device,
    );
}

it('admite un discarded_scan sin persona solo por su quiosco', function (): void {
    expect(hallazgo(AnomalyType::DISCARDED_SCAN, null, 'device-1')->deviceUuid)->toBe('device-1')
        ->and(hallazgo(AnomalyType::DISCARDED_SCAN, 'employee-1', null)->employeeUuid)->toBe('employee-1');
})->group('RN-22');

it('no admite ningun otro hallazgo sin persona ni uno con persona y quiosco', function (AnomalyType $type, ?string $employee, ?string $device): void {
    expect(static fn (): DetectedAnomaly => hallazgo($type, $employee, $device))->toThrow(InvalidArgumentException::class);
})->with([
    'otro tipo sin persona' => [AnomalyType::LONG_SHIFT, null, 'device-1'],
    'sin persona ni quiosco' => [AnomalyType::DISCARDED_SCAN, null, null],
    'quiosco vacio' => [AnomalyType::DISCARDED_SCAN, null, ''],
    'persona y quiosco' => [AnomalyType::DISCARDED_SCAN, 'employee-1', 'device-1'],
    'persona vacia' => [AnomalyType::DISCARDED_SCAN, ' ', null],
])->group('RN-22');

it('valida el descarte sin atribuir', function (string $scanId, string $quiosco, int $intentos): void {
    expect(static fn (): UnattributedServerDiscard => sinAtribuir(intentos: $intentos, quiosco: $quiosco, scanId: $scanId))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sin scan_id' => ['', 'device-1', 10],
    'sin quiosco' => ['scan-1', '', 10],
    'sin intentos' => ['scan-1', 'device-1', 0],
])->group('RN-22');

it('abre la incidencia sin persona dentro de la ventana creible y no fuera', function (string $ocurrido, bool $abre): void {
    // 15 min de desfase y 31 dias de ventana.
    $politica = new DiscardedScanReviewPolicy(900, 31);

    expect($politica->opensOwnerlessIncident(sinAtribuir(ocurrido: $ocurrido)))->toBe($abre);
})->with([
    'una hora antes' => ['2026-08-15 07:05:00', true],
    'en el borde del desfase' => ['2026-08-15 08:20:00', true],
    'un segundo despues del desfase' => ['2026-08-15 08:20:01', false],
    'en el borde de la ventana' => ['2026-07-15 08:05:00', true],
    'un segundo antes de la ventana' => ['2026-07-15 08:04:59', false],
])->group('RN-22');
