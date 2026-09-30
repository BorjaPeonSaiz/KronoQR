<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Policy\PinAttemptRecoveryPolicy;
use App\Modules\Attendance\Domain\ValueObject\RejectedPinAttempt;

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
