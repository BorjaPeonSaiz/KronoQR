<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;
use App\Modules\Attendance\Domain\ValueObject\ScanFailureHistory;

/*
 * RN-22, ADR-061: el umbral del descarte por el servidor y la historia de
 * fallos no pueden representar un estado imposible. Un umbral por debajo de
 * los suelos no se construye: el error salta al leer la configuracion, no el
 * dia que un fichaje valido se descarta antes de tiempo.
 */

it('admite los suelos y el cupo a cero', function (): void {
    $threshold = new ScanDiscardThreshold(3, 15, 0);

    expect($threshold->minAttempts)->toBe(3)
        ->and($threshold->minSpanMinutes)->toBe(15)
        ->and($threshold->dailyBudget)->toBe(0)
        ->and(ScanDiscardThreshold::ATTEMPTS_FLOOR)->toBe(3)
        ->and(ScanDiscardThreshold::SPAN_FLOOR_MINUTES)->toBe(15);
})->group('RN-22');

it('no construye un umbral por debajo de los suelos', function (int $attempts, int $minutes, int $budget): void {
    expect(fn (): ScanDiscardThreshold => new ScanDiscardThreshold($attempts, $minutes, $budget))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'dos intentos' => [2, 60, 3],
    'cero intentos' => [0, 60, 3],
    'catorce minutos' => [10, 14, 3],
    'cero minutos' => [10, 0, 3],
    'cupo negativo' => [10, 60, -1],
])->group('RN-22');

it('solo el cupo a cero apaga el descarte', function (int $budget, bool $disabled): void {
    expect((new ScanDiscardThreshold(10, 60, $budget))->isDisabled())->toBe($disabled);
})->with([
    'cero apaga' => [0, true],
    'uno no' => [1, false],
    'tres no' => [3, false],
])->group('RN-22');

it('la historia incluye el intento actual y va en UTC', function (): void {
    $first = new DateTimeImmutable('2026-10-23 07:00:00', new DateTimeZone('UTC'));
    $history = new ScanFailureHistory(1, $first);

    expect($history->attempts)->toBe(1)
        ->and($history->firstFailedAt)->toBe($first)
        ->and(fn (): ScanFailureHistory => new ScanFailureHistory(0, $first))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ScanFailureHistory => new ScanFailureHistory(-3, $first))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ScanFailureHistory => new ScanFailureHistory(
            5,
            new DateTimeImmutable('2026-10-23 09:00:00', new DateTimeZone('Europe/Madrid')),
        ))->toThrow(InstantIsNotUtc::class);
})->group('RN-22');
