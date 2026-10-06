<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\PinAttemptReservation;

/*
 * El desenlace de reservar un intento de PIN antes de compararlo (RS-12,
 * ADR-050): o llega bloqueado, o queda anotado y quiza abre el bloqueo.
 */

it('lleva los segundos que faltan cuando el intento llega bloqueado', function (): void {
    $reserva = PinAttemptReservation::locked(1);

    expect($reserva->isLocked())->toBeTrue()
        ->and($reserva->lockSeconds())->toBe(1)
        ->and($reserva->openedSeconds())->toBe(0)
        ->and($reserva->opensLockout())->toBeFalse();
})->group('RS-12');

it('no admite un bloqueo sin segundos', function (int $segundos): void {
    expect(fn (): PinAttemptReservation => PinAttemptReservation::locked($segundos))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1])->group('RS-12');

it('anota el intento sin bloqueo cuando no alcanza ningun escalon', function (): void {
    $reserva = PinAttemptReservation::open();

    expect($reserva->isLocked())->toBeFalse()
        ->and($reserva->lockSeconds())->toBe(0)
        ->and($reserva->openedSeconds())->toBe(0)
        ->and($reserva->opensLockout())->toBeFalse();
})->group('RS-12');

it('dice cuanto dura el bloqueo que abre el intento', function (): void {
    $reserva = PinAttemptReservation::open(1);

    expect($reserva->isLocked())->toBeFalse()
        ->and($reserva->openedSeconds())->toBe(1)
        ->and($reserva->opensLockout())->toBeTrue();
})->group('RS-12');

it('no admite un bloqueo abierto de duracion negativa', function (): void {
    expect(fn (): PinAttemptReservation => PinAttemptReservation::open(-1))
        ->toThrow(InvalidArgumentException::class);
})->group('RS-12');
